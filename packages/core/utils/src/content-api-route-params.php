<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/utils/src/content-api-route-params.ts.
 *
 * Upstream derives extra query/input keys from a route's Zod request schema. Here a "route-like"
 * value is an array (or object with a `request` property) of the shape
 * `['request' => ['query' => ['search' => <validator>], 'body' => ['application/json' => ['shape' => ['clientMutationId' => <validator>]]]]]`.
 * A `<validator>` may be a Zod schema (`Strapi\Utils\Zod\ZodType`, parsed with `safeParse` as upstream
 * does), a `callable(mixed): mixed` returning the parsed value or throwing, a
 * `Strapi\Utils\ParamValidator`, or anything else (then only the key matters and no validation runs).
 *
 * @phpstan-type RouteLike array{request?: array{query?: array<string, mixed>, body?: array<string, mixed>}}|object|null
 */
final class ContentApiRouteParams
{
    /**
     * Extra query param keys from the route's request.query (excluding core ALLOWED_QUERY_PARAM_KEYS).
     *
     * @param RouteLike $route
     * @return list<string>
     */
    public static function getExtraQueryKeysFromRoute(array|object|null $route): array
    {
        $query = self::routeQuery($route);
        if ($query === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', array_keys($query)),
            static fn (string $key): bool => !in_array($key, ContentApiConstants::ALLOWED_QUERY_PARAM_KEYS, true),
        ));
    }

    /**
     * Root-level keys from the route's request.body['application/json'] schema shape.
     *
     * @param RouteLike $route
     * @return list<string>
     */
    public static function getExtraRootKeysFromRouteBody(array|object|null $route): array
    {
        $shape = self::routeBodyShape($route);

        return $shape === null ? [] : array_map('strval', array_keys($shape));
    }

    /**
     * @param RouteLike $route
     * @return array<string, mixed>|null
     */
    public static function routeQuery(array|object|null $route): ?array
    {
        $request = self::get($route, 'request');
        $query = self::get($request, 'query');

        return is_array($query) ? $query : null;
    }

    /**
     * @param RouteLike $route
     * @return array<string, mixed>|null
     */
    public static function routeBodyShape(array|object|null $route): ?array
    {
        $request = self::get($route, 'request');
        $body = self::get($request, 'body');
        $json = self::get($body, 'application/json');
        if ($json === null) {
            return null;
        }
        $shape = self::get($json, 'shape');
        if (is_array($shape)) {
            return $shape;
        }

        return is_array($json) && !array_key_exists('shape', $json) ? $json : null;
    }

    /**
     * Run a validator from a route schema. Returns `[true, parsedValue]` or `[false, errorMessage]`;
     * `[true, $value]` when the validator cannot be run.
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function runValidator(mixed $validator, mixed $value): array
    {
        if ($validator instanceof ZodType) {
            $result = $validator->safeParse($value);

            return $result['success'] ? [true, $result['data']] : [false, $result['error']?->getMessage() ?? 'Validation failed'];
        }
        if ($validator instanceof ParamValidator) {
            return $validator->safeParse($value);
        }
        if (is_callable($validator)) {
            try {
                return [true, $validator($value)];
            } catch (\Throwable $e) {
                return [false, $e->getMessage()];
            }
        }

        return [true, $value];
    }

    private static function get(mixed $container, string $key): mixed
    {
        if (is_array($container)) {
            return $container[$key] ?? null;
        }
        if (is_object($container)) {
            if (isset($container->{$key})) {
                return $container->{$key};
            }
            if (method_exists($container, $key)) {
                return $container->{$key}();
            }
        }

        return null;
    }
}
