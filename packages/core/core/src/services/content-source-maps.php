<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Port of packages/core/core/src/services/content-source-maps.ts (`strapi.get('content-source-maps')`):
 * when a content API request sends `strapi-encode-source-maps: true`, every encodable string field of
 * the response is stega-encoded with its source (`documentId`, `type`, `path`, `model`, `kind`,
 * `locale`) for the admin preview's click-to-edit.
 *
 * `vercelStegaCombine` is a port of `@vercel/stega` 0.1.2 (CLAUDE.md rule 7: port, don't
 * substitute): the JSON metadata is appended to the text as invisible characters, a prefix of four
 * U+200B then four base-4 digits per character, each digit one of U+200B, U+200C, U+200D, U+FEFF.
 *
 * @phpstan-type FieldContentSourceMap array{documentId: mixed, type: string, path: string|null, kind?: mixed, model?: mixed, locale?: mixed, fieldPath?: string|null}
 * @phpstan-type BlocksEncodeMetadata array{fieldPath: string|null, kind?: mixed, model?: mixed, locale?: mixed, documentId: mixed}
 */
final class ContentSourceMaps
{
    private const ENCODABLE_TYPES = [
        'string', 'text', 'richtext', 'biginteger', 'date', 'time', 'datetime', 'timestamp', 'boolean',
        'enumeration', 'json', 'media', 'email', 'password',
        /*
         * We cannot modify the response shape, so types that aren't based on string cannot be encoded:
         * json (object), integer/float/decimal (number), boolean, uid (would mess up URLs). Blocks are
         * handled in a dedicated branch: the first text leaf of each visual block is encoded.
         */
    ];

    // TODO: use a centralized store for these fields that would be shared with the CM and CTB
    private const EXCLUDED_FIELDS = ['id', 'documentId', 'locale', 'localizations', 'created_by', 'updated_by', 'created_at', 'updated_at', 'publishedAt'];

    /** `@vercel/stega`'s base-4 alphabet (digit → code point). */
    private const STEGA_DIGITS = [0 => 8203, 1 => 8204, 2 => 8205, 3 => 65279];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createContentSourceMapsService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /**
     * `vercelStegaCombine(text, json, skip)` of @vercel/stega 0.1.2. With `skip: 'auto'` dates and
     * URLs are left untouched; Strapi always passes `false`.
     */
    public static function vercelStegaCombine(string $text, mixed $json, bool|string $skip = 'auto'): string
    {
        if ($skip === true || ($skip === 'auto' && (self::isDate($text) || self::isUrl($text)))) {
            return $text;
        }

        return $text . self::vercelStegaEncode($json);
    }

    /** `vercelStegaEncode(json)`: the invisible encoding of `JSON.stringify(json)` (ASCII only). */
    public static function vercelStegaEncode(mixed $json): string
    {
        $string = (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $zero = mb_chr(self::STEGA_DIGITS[0], 'UTF-8');
        $out = str_repeat($zero, 4);

        foreach (mb_str_split($string) as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code > 255) {
                throw new \InvalidArgumentException("Only ASCII edit info can be encoded. Error attempting to encode {$string} on character {$char} ({$code})");
            }
            foreach (str_split(str_pad(base_convert((string) $code, 10, 4), 4, '0', STR_PAD_LEFT)) as $digit) {
                $out .= mb_chr(self::STEGA_DIGITS[(int) $digit], 'UTF-8');
            }
        }

        return $out;
    }

    /** @vercel/stega's `isDate` heuristic (`Date.parse` succeeding on a date-looking string). */
    private static function isDate(string $text): bool
    {
        if (is_numeric($text)) {
            return false;
        }
        if (preg_match('/[a-z]/i', $text) === 1 && preg_match('/\d+(?:[-:\/]\d+){2}(?:T\d+(?:[-:\/]\d+){1,2}(\.\d+)?Z?)?/', $text) !== 1) {
            return false;
        }

        return strtotime($text) !== false;
    }

    /** `new URL(t, t.startsWith('/') ? 'https://acme.com' : undefined)` succeeds */
    private static function isUrl(string $text): bool
    {
        if (str_starts_with($text, '/')) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.\-]*:/i', $text) === 1;
    }

    /**
     * `URLSearchParams#toString()` (application/x-www-form-urlencoded: `*-._` and alphanumerics kept,
     * a space is `+`, every other byte percent-encoded).
     *
     * @param array<string, string> $params
     */
    private static function urlSearchParams(array $params): string
    {
        $encode = static fn (string $value): string => str_replace('%20', '+', (string) preg_replace_callback(
            '/[^A-Za-z0-9*\-._]/',
            static fn (array $m): string => '%' . strtoupper(bin2hex($m[0])),
            $value,
        ));
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $encode((string) $key) . '=' . $encode($value);
        }

        return implode('&', $pairs);
    }

    /** @param FieldContentSourceMap $metadata */
    public function encodeField(string $text, array $metadata): string
    {
        /*
         * Combine all metadata into into a one string so we only have to deal with one data-atribute
         * on the frontend. Make it human readable because that data-attribute may be set manually by
         * users for fields that don't support sourcemap encoding.
         */
        $params = [
            'documentId' => self::str($metadata['documentId'] ?? null),
            'type' => $metadata['type'],
            'path' => self::str($metadata['path'] ?? null),
        ];
        foreach (['model', 'kind', 'locale', 'fieldPath'] as $key) {
            $value = $metadata[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== false) {
                $params[$key] = self::str($value);
            }
        }

        return self::vercelStegaCombine($text, ['strapiSource' => self::urlSearchParams($params)], false);
    }

    /**
     * Injects one stega marker per visual block of a blocks AST (the first text leaf, or the image
     * alt text), with the blocks field path for every marker. Code blocks are skipped.
     *
     * @param BlocksEncodeMetadata $metadata
     */
    public function encodeBlocks(mixed $blocks, array $metadata): mixed
    {
        return self::encodeBlocksWith($blocks, $metadata, $this->encodeField(...));
    }

    /**
     * Upstream's exported pure `encodeBlocks(blocks, metadata, encodeField)`.
     *
     * @param BlocksEncodeMetadata $metadata
     * @param callable(string, FieldContentSourceMap): string $encodeField
     */
    public static function encodeBlocksWith(mixed $blocks, array $metadata, callable $encodeField): mixed
    {
        if (!is_array($blocks) || !array_is_list($blocks)) {
            return $blocks;
        }

        return array_map(static function (mixed $block) use ($metadata, $encodeField): mixed {
            if (!is_array($block)) {
                return $block;
            }

            return match ($block['type'] ?? null) {
                // Skip encoding — encoding code content would corrupt the syntax.
                'code' => $block,
                'image' => self::encodeImageBlock($block, $metadata, $encodeField),
                'list' => self::encodeListBlock($block, $metadata, $encodeField),
                'paragraph', 'heading', 'quote' => self::encodeFirstTextLeaf($block, $metadata, $encodeField)['node'],
                default => $block,
            };
        }, $blocks);
    }

    /**
     * @param BlocksEncodeMetadata $metadata
     * @return FieldContentSourceMap
     */
    private static function blocksFieldMetadata(array $metadata): array
    {
        return [...$metadata, 'documentId' => $metadata['documentId'] ?? null, 'path' => $metadata['fieldPath'], 'type' => 'blocks'];
    }

    /**
     * Walks a subtree depth-first and stega-encodes the first `{ type: 'text', text }` leaf only.
     *
     * @param BlocksEncodeMetadata $metadata
     * @param callable(string, FieldContentSourceMap): string $encodeField
     * @return array{node: mixed, encoded: bool}
     */
    private static function encodeFirstTextLeaf(mixed $node, array $metadata, callable $encodeField): array
    {
        if (!is_array($node)) {
            return ['node' => $node, 'encoded' => false];
        }

        if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
            return ['node' => [...$node, 'text' => $encodeField($node['text'], self::blocksFieldMetadata($metadata))], 'encoded' => true];
        }

        if (is_array($node['children'] ?? null) && array_is_list($node['children'])) {
            $encoded = false;
            $children = [];
            foreach ($node['children'] as $child) {
                if ($encoded) {
                    $children[] = $child;
                    continue;
                }
                $result = self::encodeFirstTextLeaf($child, $metadata, $encodeField);
                $encoded = $result['encoded'];
                $children[] = $result['node'];
            }

            return ['node' => [...$node, 'children' => $children], 'encoded' => $encoded];
        }

        return ['node' => $node, 'encoded' => false];
    }

    /**
     * @param array<string, mixed> $listNode
     * @param BlocksEncodeMetadata $metadata
     * @param callable(string, FieldContentSourceMap): string $encodeField
     * @return array<string, mixed>
     */
    private static function encodeListBlock(array $listNode, array $metadata, callable $encodeField): array
    {
        $children = is_array($listNode['children'] ?? null) ? $listNode['children'] : [];

        return [...$listNode, 'children' => array_map(static function (mixed $child) use ($metadata, $encodeField): mixed {
            if (!is_array($child)) {
                return $child;
            }

            return match ($child['type'] ?? null) {
                'list-item' => self::encodeFirstTextLeaf($child, $metadata, $encodeField)['node'],
                'list' => self::encodeListBlock($child, $metadata, $encodeField),
                default => $child,
            };
        }, $children)];
    }

    /**
     * Encodes the alt text (not the URL: encoding it corrupts the src attribute), only when it is a
     * non-empty string.
     *
     * @param array<string, mixed> $imageNode
     * @param BlocksEncodeMetadata $metadata
     * @param callable(string, FieldContentSourceMap): string $encodeField
     * @return array<string, mixed>
     */
    private static function encodeImageBlock(array $imageNode, array $metadata, callable $encodeField): array
    {
        if (!is_array($imageNode['image'] ?? null)) {
            return $imageNode;
        }

        $alternativeText = $imageNode['image']['alternativeText'] ?? null;
        if (!is_string($alternativeText) || $alternativeText === '') {
            return $imageNode;
        }

        return [...$imageNode, 'image' => [...$imageNode['image'], 'alternativeText' => $encodeField($alternativeText, self::blocksFieldMetadata($metadata))]];
    }

    /** @param Schema|array<string, mixed> $schema */
    public function encodeEntry(mixed $data, Schema|array $schema): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $visitor = function (VisitorOptions $options, VisitorUtils $utils) use ($data): void {
            $attribute = $options->attribute;
            if ($attribute === null || in_array($options->key, self::EXCLUDED_FIELDS, true)) {
                return;
            }

            $schema = $options->schema;
            $kind = $schema instanceof Schema ? $schema->kind : ($schema['kind'] ?? null);
            $model = $schema instanceof Schema ? $schema->uid : ($schema['uid'] ?? null);
            $value = $options->value;

            if (($attribute['type'] ?? null) === 'blocks' && is_array($value) && array_is_list($value)) {
                $utils->set($options->key, $this->encodeBlocks($value, [
                    'fieldPath' => $options->path->rawWithIndices,
                    'kind' => $kind,
                    'model' => $model,
                    'locale' => $data['locale'] ?? null,
                    'documentId' => $data['documentId'] ?? null,
                ]));

                return;
            }

            if (in_array($attribute['type'] ?? null, self::ENCODABLE_TYPES, true) && is_string($value)) {
                // For inner fields of a multi-media field's items (e.g. `medias.0.url`), drop the array
                // index so all items share the same encoded path.
                $parent = $options->parent;
                $parentAttr = $parent?->attribute;
                $isInsideMultiMedia = ($parentAttr['type'] ?? null) === 'media' && ($parentAttr['multiple'] ?? null) === true;
                $encodedPath = $isInsideMultiMedia && $parent?->path->rawWithIndices !== null
                    ? "{$parent->path->rawWithIndices}.{$options->key}"
                    : $options->path->rawWithIndices;

                $utils->set($options->key, $this->encodeField($value, [
                    'path' => $encodedPath,
                    'type' => (string) $attribute['type'],
                    'kind' => $kind,
                    'model' => $model,
                    'locale' => $data['locale'] ?? null,
                    'documentId' => $data['documentId'] ?? null,
                ]));
            }
        };

        return TraverseEntity::traverse($visitor, [
            'schema' => $schema,
            'getModel' => fn (string $uid): mixed => $this->strapi->getModel($uid),
        ], $data);
    }

    /** @param Schema|array<string, mixed> $schema */
    public function encodeSourceMaps(mixed $data, Schema|array $schema): mixed
    {
        try {
            if (is_array($data) && array_is_list($data)) {
                return array_map(fn (mixed $item): mixed => $this->encodeSourceMaps($item, $schema), $data);
            }

            if (!is_array($data)) {
                return $data;
            }

            return $this->encodeEntry($data, $schema);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('Error encoding source maps:', ['error' => $error]);

            return $data;
        }
    }

    private static function str(mixed $value): string
    {
        // `URLSearchParams#set(key, value)` stringifies: undefined → "undefined"
        return is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : ($value === null ? 'undefined' : (string) json_encode($value));
    }
}
