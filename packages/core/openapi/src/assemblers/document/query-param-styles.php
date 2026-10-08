<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document;

/** Port of packages/core/openapi/src/assemblers/document/query-param-styles.ts. */
final class QueryParamStyles
{
    /**
     * Query params whose values are open-ended records parsed from bracket notation
     * (e.g. filters[title][$eq]=hello). These are described as deepObject in OpenAPI
     * rather than expanded into fixed sub-keys.
     */
    public const DEEP_OBJECT_QUERY_PARAMS = ['filters'];

    /** @param array<string, mixed>|\stdClass $schema */
    public static function shouldUseDeepObjectStyle(string $name, array|\stdClass $schema): bool
    {
        if (in_array($name, self::DEEP_OBJECT_QUERY_PARAMS, true)) {
            return true;
        }

        if (!is_array($schema)) {
            return false;
        }

        $properties = $schema['properties'] ?? null;

        return ($schema['type'] ?? null) === 'object'
            && (!self::hasEntries($properties))
            && array_key_exists('additionalProperties', $schema)
            && $schema['additionalProperties'] !== false;
    }

    /** @param array<string, mixed>|\stdClass $schema */
    public static function hasExpandableObjectProperties(array|\stdClass $schema): bool
    {
        return is_array($schema) && ($schema['type'] ?? null) === 'object' && self::hasEntries($schema['properties'] ?? null);
    }

    private static function hasEntries(mixed $properties): bool
    {
        return is_array($properties) && $properties !== [];
    }
}
