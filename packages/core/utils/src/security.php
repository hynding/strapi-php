<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/security.ts.
 *
 * `extendMiddlewareConfiguration` reproduces lodash/fp `mergeWith` with upstream's customizer:
 * a deep, non-mutating merge where plain objects (associative arrays) merge key by key, arrays
 * (lists) are concatenated and de-duplicated (like `Array.from(new Set(...))`, strict
 * comparison), and any other source value replaces the destination value.
 *
 * PHP has no separate empty object: an empty array on the destination side is treated as an
 * array (concatenated with the source) unless the source is a non-empty associative array, in
 * which case it is treated as an empty object and merged into.
 */
final class Security
{
    /** @var array<string, list<string>> */
    public const array CSP_DEFAULTS = [
        'connect-src' => ["'self'", 'https:'],
        'img-src' => ["'self'", 'data:', 'blob:', 'https://market-assets.strapi.io'],
        'media-src' => ["'self'", 'data:', 'blob:'],
    ];

    /**
     * Utility to extend Strapi middleware configuration. Mainly used to extend the CSP directives
     * from the security middleware.
     *
     * @param list<string|array<string, mixed>> $middlewares array of middleware configurations
     * @param array<string, mixed> $middleware middleware configuration to merge/add (`name`, `config`)
     * @return list<string|array<string, mixed>> a new array with the configuration merged
     */
    public static function extendMiddlewareConfiguration(array $middlewares, array $middleware): array
    {
        $name = $middleware['name'] ?? null;

        return array_map(static function (string|array $currentMiddleware) use ($middleware, $name): string|array {
            if (is_string($currentMiddleware) && $currentMiddleware === $name) {
                // Use the new config object if the middleware has no config property yet
                return $middleware;
            }

            if (is_array($currentMiddleware) && array_key_exists('name', $currentMiddleware) && $currentMiddleware['name'] === $name) {
                // Deep merge (+ concat arrays) the new config with the current middleware config
                /** @var array<string, mixed> */
                return self::merge($currentMiddleware, $middleware);
            }

            return $currentMiddleware;
        }, array_values($middlewares));
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function merge(mixed $objValue, mixed $srcValue): mixed
    {
        // customizer: arrays are concatenated and de-duplicated
        if (is_array($objValue) && array_is_list($objValue) && !($objValue === [] && is_array($srcValue) && $srcValue !== [] && !array_is_list($srcValue))) {
            $concat = is_array($srcValue) && array_is_list($srcValue) ? [...$objValue, ...$srcValue] : [...$objValue, $srcValue];
            $unique = [];
            foreach ($concat as $item) {
                if (!in_array($item, $unique, true)) {
                    $unique[] = $item;
                }
            }

            return $unique;
        }

        // default lodash merge: plain objects are merged key by key, anything else is replaced
        if (self::isObject($srcValue) && is_array($srcValue)) {
            $result = self::isObject($objValue) && is_array($objValue) ? $objValue : [];
            foreach ($srcValue as $key => $value) {
                $result[$key] = array_key_exists($key, $result) ? self::merge($result[$key], $value) : self::merge(null, $value);
            }

            return $result;
        }

        return $srcValue;
    }
}
