<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/route-serialization.ts: sanitizes content API route objects for
 * safe JSON serialization by removing the Zod validation fields (`request` / `response`).
 *
 * NOTE: some content API routes are returned to the admin panel e.g. to populate the users and
 * permissions roles page. We need to ensure that the routes can be serialized to JSON without errors.
 */
final class RouteSerialization
{
    /**
     * @param array<array-key, mixed> $route
     * @return array<array-key, mixed>
     */
    public static function sanitizeRouteForSerialization(array $route): array
    {
        unset($route['request'], $route['response']);

        return $route;
    }

    /**
     * @param array<array-key, mixed> $routes
     * @return list<array<array-key, mixed>>
     */
    public static function sanitizeRoutesArrayForSerialization(array $routes): array
    {
        $result = [];
        foreach ($routes as $route) {
            // `!!route && typeof route === 'object'`
            if (is_array($route)) {
                $result[] = self::sanitizeRouteForSerialization($route);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<array-key, mixed>
     */
    public static function sanitizeRoutesMapForSerialization(array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            $result[$key] = is_array($value) && array_is_list($value) ? self::sanitizeRoutesArrayForSerialization($value) : $value;
        }

        return $result;
    }
}
