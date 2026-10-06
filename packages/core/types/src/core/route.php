<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/**
 * A route as written in routes/*.php. The array shape is upstream's:
 *   ['method' => 'GET', 'path' => '/articles/:id', 'handler' => 'article.findOne',
 *    'config' => ['policies' => [...], 'middlewares' => [...], 'auth' => false, 'prefix' => '']]
 *
 * Mirrors packages/core/types/src/core/route.ts.
 *
 * @phpstan-type RouteConfig array{policies?: list<mixed>, middlewares?: list<mixed>, auth?: bool|array<string, mixed>, prefix?: string, description?: string, tag?: array<string, mixed>, deprecated?: bool}
 * @phpstan-type RouteArray array{method: string, path: string, handler: string|callable, config?: RouteConfig, info?: array{apiName?: string, pluginName?: string, type?: string}, request?: array<string, mixed>, response?: mixed}
 */
final class Route
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** @param RouteArray $route */
    public static function normalize(array $route, string $prefix = ''): array
    {
        $route['method'] = strtoupper($route['method']);
        $route['config'] ??= [];
        $route['config']['policies'] ??= [];
        $route['config']['middlewares'] ??= [];
        $route['info'] ??= [];
        $routePrefix = $route['config']['prefix'] ?? $prefix;
        $path = '/' . ltrim($route['path'], '/');
        $route['path'] = rtrim($routePrefix, '/') . $path;
        if ($route['path'] !== '/' ) {
            $route['path'] = rtrim($route['path'], '/');
        }

        return $route;
    }
}
