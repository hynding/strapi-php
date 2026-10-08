<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Schemas;

use Strapi\ContentManager\Mcp\Sanitizers\ShapeRelations;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/mcp/schemas/output-schemas.ts. */
final class OutputSchemas
{
    private static function relationIdentity(): ZodObject
    {
        return z::object([
            'documentId' => z::string(),
            'locale' => z::string()->optional(),
            '__type' => z::string()->optional(),
            // Computed publish status preserved on `localizations` entries (calculate, then strip).
            'status' => z::string()->optional(),
        ]);
    }

    /** @param array<string, array<string, mixed>> $attributes */
    private static function buildAttributeSchema(string $key, array $attributes): ZodType
    {
        $attribute = $attributes[$key] ?? null;

        if (($attribute['type'] ?? null) !== 'relation') {
            return z::unknown()->optional();
        }

        // admin::user relations are out of scope — left as unknown
        if (($attribute['target'] ?? null) === 'admin::user') {
            return z::unknown()->optional();
        }

        // Must match the runtime shaping cardinality (shape-relations): the structuredContent is
        // validated against this schema, so a mismatch fails the tool call.
        if (ShapeRelations::isManyRelationForMcp($attribute)) {
            return z::array(self::relationIdentity())->optional();
        }

        return self::relationIdentity()->nullable()->optional();
    }

    /**
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $permittedFields
     */
    private static function buildOutputDataSchema(array $attributes, ?array $permittedFields): ZodType
    {
        $readableKeys = array_values(array_filter(array_map('strval', array_keys($attributes)), static fn (string $key): bool => $permittedFields === null || isset($permittedFields[$key])));

        if ($readableKeys === []) {
            return z::record(z::string(), z::unknown());
        }

        $shape = [];
        foreach ($readableKeys as $key) {
            $shape[$key] = self::buildAttributeSchema($key, $attributes);
        }

        return z::object($shape)->loose();
    }

    /**
     * The output schema of a single-document response (`{ data, meta }`), its fields constrained to
     * `readFields` when non-null (RBAC field filtering).
     *
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $readFields
     */
    public static function buildDocumentOutputSchema(array $attributes, ?array $readFields): ZodObject
    {
        return z::object([
            'data' => self::buildOutputDataSchema($attributes, $readFields)->nullable(),
            'meta' => z::object([
                'availableLocales' => z::array(z::record(z::string(), z::unknown()))->optional(),
                'availableStatus' => z::array(z::record(z::string(), z::unknown()))->optional(),
            ])->optional(),
        ])->loose();
    }

    /**
     * The output schema of a paginated list response (`{ results, pagination }`).
     *
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $readFields
     */
    public static function buildListOutputSchema(array $attributes, ?array $readFields): ZodObject
    {
        return z::object([
            'results' => z::array(self::buildOutputDataSchema($attributes, $readFields)),
            'pagination' => z::object([
                'page' => z::number(),
                'pageSize' => z::number(),
                'pageCount' => z::number(),
                'total' => z::number(),
            ]),
        ])->loose();
    }

    /**
     * The output schema of a delete response (`{ data }`): delete handlers return an empty data
     * object, so no document relation field is required.
     *
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, true>|null $readFields
     */
    public static function buildDeleteOutputSchema(array $attributes, ?array $readFields): ZodObject
    {
        return z::object([
            'data' => z::object([])->loose()->nullable(),
        ])->loose();
    }
}
