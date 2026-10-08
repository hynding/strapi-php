<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

use Strapi\Types\Schema\Schema as StrapiSchema;

/**
 * Port of src/utils/schema.ts.
 */
final class Schema
{
    /** List of schema properties that should be kept when sanitizing schemas */
    public const array VALID_SCHEMA_PROPERTIES = [
        'collectionName',
        'info',
        'options',
        'pluginOptions',
        'attributes',
        'kind',
        'modelType',
        'modelName',
        'uid',
        'plugin',
        'globalId',
    ];

    /**
     * Sanitize a schemas dictionary by omitting unwanted properties
     *
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mapSchemasValues(array $schemas): array
    {
        $out = [];
        foreach ($schemas as $uid => $schema) {
            $picked = [];
            // lodash pick keeps the order of the picked paths
            foreach (self::VALID_SCHEMA_PROPERTIES as $key) {
                if (array_key_exists($key, $schema)) {
                    $picked[$key] = $schema[$key];
                }
            }
            $out[$uid] = $picked;
        }

        return $out;
    }

    /**
     * `JSON.parse(JSON.stringify(schemas))`: Schema objects become plain arrays.
     *
     * @param array<string, StrapiSchema|array<string, mixed>> $schemas
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schemasToValidJSON(array $schemas): array
    {
        $out = [];
        foreach ($schemas as $uid => $schema) {
            $array = $schema instanceof StrapiSchema ? $schema->toArray() : $schema;
            $decoded = json_decode(Json::stringify($array), true, 512, JSON_THROW_ON_ERROR);
            $out[$uid] = is_array($decoded) ? $decoded : [];
        }

        return $out;
    }
}
