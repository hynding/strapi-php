<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp\Sanitizers;

/**
 * Port of server/src/mcp/sanitizers/sanitize-media.ts.
 *
 * @phpstan-type MediaFolderNode array{id: int, name: string, children: list<mixed>}
 */
final class SanitizeMedia
{
    /** Coerces a value to a string, or null when it is absent. Timestamps arrive as Date or string. */
    private static function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.v\Z');
        }

        return is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value);
    }

    private static function toNullableNumber(mixed $value): int|float|null
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }

    private static function number(mixed $value): int|float
    {
        return is_numeric($value) ? $value + 0 : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Projects a raw file row onto the MCP asset shape.
     *
     * This is an allowlist: every exposed key is named explicitly, so `provider`,
     * `provider_metadata` (which can carry provider credentials), `hash`, `formats`, `related`,
     * and the private `folderPath` never reach an MCP client.
     *
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    public static function sanitizeMediaAsset(array $asset): array
    {
        $folder = $asset['folder'] ?? null;

        return [
            'id' => self::number($asset['id'] ?? null),
            'name' => self::string($asset['name'] ?? ''),
            'alternativeText' => self::toNullableString($asset['alternativeText'] ?? null),
            'caption' => self::toNullableString($asset['caption'] ?? null),
            'url' => self::string($asset['url'] ?? ''),
            'mime' => self::string($asset['mime'] ?? ''),
            'size' => self::number($asset['size'] ?? 0),
            'width' => self::toNullableNumber($asset['width'] ?? null),
            'height' => self::toNullableNumber($asset['height'] ?? null),
            'ext' => self::toNullableString($asset['ext'] ?? null),
            'folder' => is_array($folder) && array_key_exists('id', $folder)
                ? ['id' => self::number($folder['id']), 'name' => self::string($folder['name'] ?? '')]
                : null,
            'createdAt' => self::toNullableString($asset['createdAt'] ?? null),
            'updatedAt' => self::toNullableString($asset['updatedAt'] ?? null),
        ];
    }

    /**
     * Projects the recursive output of `folder.getStructure()` onto `{ id, name, children }`,
     * dropping `path` and `pathId` (internal materialized-path bookkeeping).
     *
     * @return list<MediaFolderNode>
     */
    public static function sanitizeMediaFolderTree(mixed $nodes): array
    {
        if (!is_array($nodes) || !array_is_list($nodes)) {
            return [];
        }

        return array_map(static fn (mixed $node): array => [
            'id' => (int) self::number(is_array($node) ? ($node['id'] ?? null) : null),
            'name' => self::string(is_array($node) ? ($node['name'] ?? '') : ''),
            'children' => self::sanitizeMediaFolderTree(is_array($node) ? ($node['children'] ?? null) : null),
        ], $nodes);
    }

    /**
     * Projects a raw folder row onto the MCP folder shape (an allowlist: `path` and `pathId` stay
     * invisible).
     *
     * `parent: null` is a claim, not a default: it means "this folder sits at the media library
     * root". A row selected without the relation cannot support that claim, so the key is omitted.
     *
     * @param array<string, mixed>|null $folder
     * @return array<string, mixed>|null
     */
    public static function sanitizeMediaFolder(?array $folder): ?array
    {
        if ($folder === null) {
            return null;
        }

        $parent = $folder['parent'] ?? null;

        $result = [
            'id' => self::number($folder['id'] ?? null),
            'name' => self::string($folder['name'] ?? ''),
        ];

        if (array_key_exists('parent', $folder)) {
            if ($parent === null) {
                $result['parent'] = null;
            } elseif (is_int($parent)) {
                $result['parent'] = ['id' => $parent];
            } elseif (!is_array($parent) || !array_key_exists('id', $parent)) {
                $result['parent'] = null;
            } else {
                $result['parent'] = ['id' => self::number($parent['id']), 'name' => self::string($parent['name'] ?? '')];
            }
        }

        $result['createdAt'] = self::toNullableString($folder['createdAt'] ?? null);
        $result['updatedAt'] = self::toNullableString($folder['updatedAt'] ?? null);

        return $result;
    }
}
