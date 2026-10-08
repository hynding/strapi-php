<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Schemas;

use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/mcp/schemas/filters-schema.ts. */
final class FiltersSchema
{
    /** All supported Strapi filter operators (excludes the experimental `$jsonSupersetOf`). */
    public const FILTER_OPERATORS = [
        '$eq', '$eqi', '$ne', '$nei', '$in', '$notIn', '$lt', '$lte', '$gt', '$gte', '$between',
        '$contains', '$notContains', '$containsi', '$notContainsi', '$startsWith', '$startsWithi',
        '$endsWith', '$endsWithi', '$null', '$notNull',
    ];

    /**
     * The Zod leaf value schema of a scalar attribute inside filter operator objects.
     *
     * @param array<string, mixed> $attr
     */
    public static function attributeTypeToFilterValue(array $attr): ZodType
    {
        switch ($attr['type'] ?? null) {
            case 'integer':
            case 'biginteger':
            case 'decimal':
            case 'float':
                return z::union([z::number(), z::array(z::number())]);
            case 'boolean':
                return z::boolean();
            case 'enumeration':
                $values = $attr['enum'] ?? null;
                if (is_array($values) && $values !== []) {
                    $values = array_values(array_map(static fn (mixed $v): string => (string) $v, $values));

                    return z::union([z::enum($values), z::array(z::enum($values))]);
                }

                return z::union([z::string(), z::array(z::string())]);
            default:
                // string, text, richtext, email, password, uid, date, datetime, time, timestamp
                return z::union([z::string(), z::array(z::string()), z::null()]);
        }
    }

    /**
     * Builds a per-content-type recursive filters schema: `$and`/`$or` take an array of filter
     * objects, `$not` one; scalar field keys take a direct value (implicit `$eq`) or an operator
     * object. With no scalar attribute the schema is `z.never()` (filters not allowed).
     *
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $permittedFields
     */
    public static function buildFiltersSchema(array $attributes, ?array $permittedFields = null): ZodType
    {
        $scalarKeys = SortSchema::getScalarAttributeKeys($attributes, $permittedFields);

        if ($scalarKeys === []) {
            return z::never();
        }

        $filtersSchema = null;
        // Lazy reference for recursion ($and / $or / $not)
        $filtersSchema = z::lazy(static function () use ($scalarKeys, $attributes, &$filtersSchema): ZodType {
            $fieldShapes = [];
            foreach ($scalarKeys as $key) {
                $valueSchema = self::attributeTypeToFilterValue($attributes[$key]);
                $operators = [];
                foreach (self::FILTER_OPERATORS as $op) {
                    $operators[$op] = $valueSchema->optional();
                }
                // Field accepts either a direct value (implicit $eq) or operator object
                $fieldShapes[$key] = z::union([$valueSchema, z::object($operators)])->optional();
            }

            $self = $filtersSchema ?? z::never(); // assigned by the time the getter runs

            return z::object([
                '$and' => z::array($self)->optional(),
                '$or' => z::array($self)->optional(),
                '$not' => $self->optional(),
                ...$fieldShapes,
            ])->strict();
        });

        return $filtersSchema
            ->optional()
            ->describe('Filter object. Supports logical operators ($and, $or, $not) and field operators ($eq, $ne, $in, $contains, $gt, $lt, etc.). Valid fields: ' . implode(', ', $scalarKeys) . '.');
    }
}
