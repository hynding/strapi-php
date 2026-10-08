<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Schemas;

use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/mcp/schemas/sort-schema.ts. */
final class SortSchema
{
    /** Attribute types considered scalar for sorting and filtering (excludes relations, components, media, json, blocks). */
    public const SCALAR_ATTRIBUTE_TYPES = [
        'string', 'text', 'richtext', 'email', 'password', 'uid', 'integer', 'biginteger', 'decimal',
        'float', 'boolean', 'date', 'datetime', 'time', 'timestamp', 'enumeration',
    ];

    /**
     * The scalar attribute keys of a content type (relation, component, dynamiczone, media, json
     * and blocks cannot be meaningfully sorted or filtered via simple operators), minus the private
     * ones and the non-permitted ones.
     *
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $permittedFields a set (keys), null = all permitted
     * @return list<string>
     */
    public static function getScalarAttributeKeys(array $attributes, ?array $permittedFields = null): array
    {
        $keys = [];
        foreach ($attributes as $key => $attr) {
            if (in_array($attr['type'] ?? null, self::SCALAR_ATTRIBUTE_TYPES, true) && ($attr['private'] ?? null) !== true) {
                $keys[] = (string) $key;
            }
        }

        if ($permittedFields !== null) {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => isset($permittedFields[$key])));
        }

        return $keys;
    }

    /**
     * Builds a per-content-type sort schema constrained to the model's scalar fields: `"title:asc"`,
     * `["title:asc", "createdAt:desc"]`, `{ title: "asc" }`, `[{ title: "asc" }]`. With no scalar
     * attribute the schema is `z.never()` (sort not allowed).
     *
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $permittedFields
     */
    public static function buildSortSchema(array $attributes, ?array $permittedFields = null): ZodType
    {
        $scalarKeys = self::getScalarAttributeKeys($attributes, $permittedFields);

        if ($scalarKeys === []) {
            return z::never();
        }

        $directionSchema = z::enum(['asc', 'desc']);
        $shape = [];
        foreach ($scalarKeys as $key) {
            $shape[$key] = $directionSchema->optional();
        }
        $sortObjectSchema = z::object($shape)->strict();

        $isPermittedSortString = static function (mixed $value) use ($scalarKeys): bool {
            if (!is_string($value) || preg_match('/^([^:]+):(asc|desc)$/', $value, $match) !== 1) {
                return true;
            }

            return in_array($match[1], $scalarKeys, true);
        };

        $stringSortSchema = z::string()->refine($isPermittedSortString, ['message' => 'Sort field must be one of: ' . implode(', ', $scalarKeys)]);

        return z::union([
            $stringSortSchema,
            z::array($stringSortSchema),
            $sortObjectSchema,
            z::array($sortObjectSchema),
        ])
            ->optional()
            ->describe('Sort expression. String: "field:asc". Array: ["field:asc"]. Object: { field: "asc" }. Valid fields: ' . implode(', ', $scalarKeys) . '.');
    }
}
