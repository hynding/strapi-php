<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Schemas;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/mcp/schemas/data-schema.ts. */
final class DataSchema
{
    /**
     * Builds a structured, strict Zod object schema for a component uid. `$visited` guards against
     * self-referencing components (a permissive record is used on a cycle).
     *
     * @param array<string, true> $visited
     */
    public static function buildComponentInputSchema(Strapi $strapi, string $componentUid, array $visited = []): ZodType
    {
        if (isset($visited[$componentUid])) {
            // Circular reference — fall back to permissive but non-empty JSON Schema
            return z::record(z::string(), z::unknown());
        }

        $component = $strapi->components()[$componentUid] ?? null;
        if ($component === null) {
            return z::record(z::string(), z::unknown());
        }

        $visited[$componentUid] = true;

        $shape = [];
        foreach (self::attributesOf($component) as $key => $attr) {
            if ($key === 'id') {
                continue;
            }
            $shape[$key] = self::attributeToInputSchema($strapi, $attr, $visited);
        }

        return z::object($shape)->strict();
    }

    /**
     * Maps one Strapi attribute to a Zod input schema with its constraints (min, max, minLength,
     * maxLength, required, enum values...). Mirrors core's route validation mappers, kept inline as
     * upstream does.
     *
     * @param array<string, mixed> $attr
     * @param array<string, true> $visited
     */
    public static function attributeToInputSchema(Strapi $strapi, array $attr, array $visited = []): ZodType
    {
        $required = ($attr['required'] ?? null) === true;
        $finish = static fn (ZodType $s): ZodType => $required ? $s : $s->optional();

        switch ($attr['type'] ?? null) {
            case 'string':
            case 'text':
            case 'richtext':
            case 'password':
                $s = z::string();
                if (isset($attr['minLength'])) {
                    $s = $s->min((int) $attr['minLength']);
                }
                if (isset($attr['maxLength'])) {
                    $s = $s->max((int) $attr['maxLength']);
                }

                return $finish($s);
            case 'email':
                return $finish(z::string()->email());
            case 'uid':
            case 'biginteger':
            case 'date':
            case 'datetime':
            case 'time':
            case 'timestamp':
                return $finish(z::string());
            case 'integer':
                $s = z::number()->int();
                if (isset($attr['min']) && is_numeric($attr['min'])) {
                    $s = $s->min($attr['min'] + 0);
                }
                if (isset($attr['max']) && is_numeric($attr['max'])) {
                    $s = $s->max($attr['max'] + 0);
                }

                return $finish($s);
            case 'decimal':
            case 'float':
                $s = z::number();
                if (isset($attr['min']) && is_numeric($attr['min'])) {
                    $s = $s->min($attr['min'] + 0);
                }
                if (isset($attr['max']) && is_numeric($attr['max'])) {
                    $s = $s->max($attr['max'] + 0);
                }

                return $finish($s);
            case 'boolean':
                return $finish(z::boolean());
            case 'enumeration':
                $values = $attr['enum'] ?? null;
                if (is_array($values) && $values !== []) {
                    return $finish(z::enum(array_values(array_map(static fn (mixed $v): string => (string) $v, $values))));
                }

                return $finish(z::string());
            case 'json':
                return $finish(z::any());
            case 'blocks':
                return $finish(BlocksSchema::buildBlocksInputSchema());
            case 'component':
                $componentUid = $attr['component'] ?? null;
                $componentSchema = is_string($componentUid)
                    ? self::buildComponentInputSchema($strapi, $componentUid, $visited)
                    : z::record(z::string(), z::unknown());

                if (($attr['repeatable'] ?? null) !== true) {
                    return $finish($componentSchema);
                }
                $s = z::array($componentSchema);
                if (isset($attr['min'])) {
                    $s = $s->min((int) $attr['min']);
                }
                if (isset($attr['max'])) {
                    $s = $s->max((int) $attr['max']);
                }

                return $finish($s);
            case 'dynamiczone':
                $s = z::array(z::any());
                if (isset($attr['min'])) {
                    $s = $s->min((int) $attr['min']);
                }
                if (isset($attr['max'])) {
                    $s = $s->max((int) $attr['max']);
                }

                return $finish($s);
            case 'media':
                return $finish(($attr['multiple'] ?? null) === true ? z::array(z::any()) : z::any());
            case 'relation':
                return $finish(self::relationInputSchema($attr));
            default:
                if (($attr['type'] ?? null) === 'customField' && is_string($attr['customField'] ?? null)) {
                    try {
                        $type = $strapi->get('custom-fields')->get($attr['customField'])['type'] ?? null;
                    } catch (\Throwable) {
                        $type = null; // not registered (upstream: `get()` returns undefined)
                    }
                    if (is_string($type)) {
                        return self::attributeToInputSchema($strapi, [...$attr, 'type' => $type], $visited);
                    }
                }

                return z::unknown();
        }
    }

    /** @param array<string, mixed> $attr */
    private static function relationInputSchema(array $attr): ZodType
    {
        $isToMany = str_ends_with((string) ($attr['relation'] ?? ''), 'ToMany');

        $relDocumentId = z::string()->min(1)->describe('Strapi document ID (e.g. "z7v8zma53x01r6oceimv922b").');

        $relLongHand = z::object([
            'documentId' => $relDocumentId,
            'locale' => z::string()->optional()->describe('Target locale. Defaults to source document locale.'),
            'status' => z::enum(['draft', 'published'])->optional()->describe('Target version status. Defaults based on draftAndPublish config.'),
        ])->strict();

        if (!$isToMany) {
            return z::union([$relDocumentId, $relLongHand, z::null()->describe('Set to null to clear the relation.')]);
        }

        $relEntry = z::union([$relDocumentId, $relLongHand]);

        $relConnectPosition = z::object([
            'before' => z::string()->optional()->describe('Document ID to insert before.'),
            'after' => z::string()->optional()->describe('Document ID to insert after.'),
            'start' => z::boolean()->optional()->describe('Insert at start of list.'),
            'end' => z::boolean()->optional()->describe('Insert at end of list (default).'),
        ])->strict();

        $relConnectEntry = z::object([
            'documentId' => $relDocumentId,
            'locale' => z::string()->optional(),
            'status' => z::enum(['draft', 'published'])->optional(),
            'position' => $relConnectPosition->optional()->describe('Ordering hint. Default: { end: true }.'),
        ])->strict();

        return z::object([
            'connect' => z::array(z::union([$relDocumentId, $relConnectEntry]))->optional()
                ->describe('Add relations. Each entry: documentId string, or { documentId, locale?, status?, position? }.'),
            'disconnect' => z::array($relEntry)->optional()
                ->describe('Remove relations. Each entry: documentId string, or { documentId, locale?, status? }.'),
            'set' => z::union([z::array($relEntry), z::null()])->optional()
                ->describe('Replace all relations. Array replaces existing; null clears all. Mutually exclusive with connect/disconnect.'),
        ])
            ->strict()
            ->refine(
                static fn (mixed $value): bool => !is_array($value) || !array_key_exists('set', $value) || (!array_key_exists('connect', $value) && !array_key_exists('disconnect', $value)),
                ['message' => 'set is mutually exclusive with connect and disconnect', 'path' => ['set']],
            );
    }

    /**
     * The per-content-type `data` schema of the model's writable attributes: system-managed keys
     * (id, documentId, timestamps, createdBy, updatedBy, localizations, locale...) and private ones
     * are left out; unknown keys are rejected (strict), so invalid field names fail at the boundary.
     *
     * @param Schema|array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $permittedFields
     */
    public static function buildDataSchema(Strapi $strapi, Schema|array $schema, array $attributes, ?array $permittedFields = null): ZodType
    {
        $shape = [];
        foreach ($attributes as $key => $attr) {
            $key = (string) $key;
            $isPermitted = $permittedFields === null || isset($permittedFields[$key]);

            if ($isPermitted && ContentTypes::isWritableAttribute($schema, $key) && !ContentTypes::isPrivateAttribute($schema, $key)) {
                $shape[$key] = self::attributeToInputSchema($strapi, $attr);
            }
        }

        return z::object($shape)->strict()->describe('Document field values to write.');
    }

    /**
     * @param Schema|array<string, mixed> $component
     * @return array<string, array<string, mixed>>
     */
    private static function attributesOf(Schema|array $component): array
    {
        $attributes = $component instanceof Schema ? $component->attributes : ($component['attributes'] ?? []);

        return is_array($attributes) ? $attributes : [];
    }
}
