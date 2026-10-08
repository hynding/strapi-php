<?php

declare(strict_types=1);

namespace Strapi\Utils\Validation;

use Strapi\Utils\Zod\Undefined;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/utils/src/validation/utilities.ts: utility functions for working with Zod
 * schemas (make them optional, readonly, or add default values) and OpenAPI component names.
 *
 * `undefined` arguments are `Undefined::Value` (a `null` default is a real default).
 */
final class Utilities
{
    /**
     * Transforms a Strapi UID into an OpenAPI-compliant component name.
     *
     * "basic.seo" → "BasicSeoEntry", "api::category.category" → "ApiCategoryCategoryDocument",
     * "plugin::upload.file" → "PluginUploadFileDocument".
     */
    public static function transformUidToValidOpenApiName(string $uid): string
    {
        $capitalize = static fn (string $str): string => $str === '' ? '' : strtoupper($str[0]) . substr($str, 1);

        $toPascalCase = static fn (string $str): string => implode('', array_map($capitalize, preg_split('/[-_]/', $str) ?: []));

        // Check if it contains double colons (other namespaced UIDs)
        if (str_contains($uid, '::')) {
            $parts = explode('::', $uid);
            $namespace = array_shift($parts);
            $namespacePart = $toPascalCase($namespace);
            $restParts = array_map($capitalize, array_map($toPascalCase, explode('.', implode('.', $parts))));

            return $capitalize($namespacePart) . implode('', $restParts) . 'Document';
        }

        if (str_contains($uid, '.')) {
            // basic.seo -> BasicSeoEntry
            $transformedParts = array_map($capitalize, array_map($toPascalCase, explode('.', $uid)));

            return implode('', $transformedParts) . 'Entry';
        }

        return $toPascalCase($capitalize($uid)) . 'Schema';
    }

    /**
     * Conditionally makes a Zod schema optional: optional unless `$required` is `true` (then
     * non-optional).
     *
     * @return \Closure(ZodType): ZodType
     */
    public static function maybeRequired(mixed $required = Undefined::Value): \Closure
    {
        return static fn (ZodType $schema): ZodType => $required !== true ? $schema->optional() : $schema->nonoptional();
    }

    /**
     * Conditionally makes a Zod schema readonly: when `$writable` is `false`.
     *
     * @return \Closure(ZodType): ZodType
     */
    public static function maybeReadonly(mixed $writable = Undefined::Value): \Closure
    {
        return static fn (ZodType $schema): ZodType => $writable !== false ? $schema : $schema->readonly();
    }

    /**
     * Conditionally adds a default value (a callable default is called) unless it is undefined.
     *
     * @return \Closure(ZodType): ZodType
     */
    public static function maybeWithDefault(mixed $defaultValue = Undefined::Value): \Closure
    {
        return static function (ZodType $schema) use ($defaultValue): ZodType {
            if ($defaultValue === Undefined::Value) {
                return $schema;
            }

            $value = $defaultValue instanceof \Closure ? $defaultValue() : $defaultValue;

            return $schema->default($value);
        };
    }

    /**
     * Conditionally applies `min` and `max` constraints (both must be defined) to a Zod string,
     * number or array schema.
     *
     * @return \Closure(ZodType): ZodType
     */
    public static function maybeWithMinMax(mixed $min = Undefined::Value, mixed $max = Undefined::Value): \Closure
    {
        return static function (ZodType $schema) use ($min, $max): ZodType {
            if ($min === Undefined::Value || $max === Undefined::Value || $min === null || $max === null) {
                return $schema;
            }

            if ($schema instanceof \Strapi\Utils\Zod\ZodNumber) {
                return $schema->min(self::number($min))->max(self::number($max));
            }

            if ($schema instanceof \Strapi\Utils\Zod\ZodString || $schema instanceof \Strapi\Utils\Zod\ZodArray) {
                return $schema->min((int) self::number($min))->max((int) self::number($max));
            }

            return $schema;
        };
    }

    /**
     * Applies a series of modifier functions to a Zod schema sequentially.
     *
     * @param list<\Closure(ZodType): ZodType> $modifiers
     */
    public static function augmentSchema(ZodType $schema, array $modifiers): ZodType
    {
        foreach ($modifiers as $modifier) {
            $schema = $modifier($schema);
        }

        return $schema;
    }

    private static function number(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_numeric($value) ? $value + 0 : 0;
    }
}
