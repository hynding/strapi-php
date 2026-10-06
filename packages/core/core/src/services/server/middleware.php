<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/server/middleware.ts: instantiate configured middlewares
 * (`'strapi::cors'`, `['name' => 'strapi::cors', 'config' => [...]]`, `['resolve' => './src/custom/middleware.php', 'config' => []]`,
 * or an inline `callable(Context, callable)`).
 *
 * @phpstan-type MiddlewareHandler callable(\Strapi\Types\Core\Context, callable): void
 */
final class Middleware
{
    /** @param array<string, mixed> $config */
    private static function instantiateMiddleware(callable $middlewareFactory, string $name, array $config, Strapi $strapi): ?callable
    {
        try {
            $handler = $middlewareFactory($config, $strapi);

            return is_callable($handler) ? $handler : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Middleware \"{$name}\": {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $route
     * @return list<MiddlewareHandler>
     */
    public static function resolveRouteMiddlewares(array $route, Strapi $strapi): array
    {
        $middlewaresConfig = $route['config']['middlewares'] ?? [];

        if (!is_array($middlewaresConfig) || !array_is_list($middlewaresConfig)) {
            throw new \RuntimeException('Route middlewares config must be an array');
        }

        return array_map(static fn (array $m): callable => $m['handler'], self::resolveMiddlewares($middlewaresConfig, $strapi));
    }

    /** The middleware used when a factory returns nothing (it only extended the app). */
    public static function dummyMiddleware(): \Closure
    {
        return static function (mixed $ctx, callable $next): void {
            $next();
        };
    }

    /**
     * Initialize every configured middleware.
     *
     * @param list<mixed> $config
     * @return list<array{name: string|null, handler: MiddlewareHandler}>
     */
    public static function resolveMiddlewares(array $config, Strapi $strapi): array
    {
        $middlewares = [];

        foreach ($config as $item) {
            if (!is_string($item) && is_callable($item)) {
                $middlewares[] = ['name' => null, 'handler' => $item];
                continue;
            }

            if (is_string($item)) {
                $middlewareFactory = $strapi->get('middlewares')->get($item);

                if ($middlewareFactory === null) {
                    throw new \RuntimeException("Middleware {$item} not found.");
                }

                $middlewares[] = ['name' => $item, 'handler' => self::instantiateMiddleware($middlewareFactory, $item, [], $strapi) ?? self::dummyMiddleware()];
                continue;
            }

            if (is_array($item)) {
                $name = $item['name'] ?? null;
                $resolve = $item['resolve'] ?? null;
                $itemConfig = is_array($item['config'] ?? null) ? $item['config'] : [];

                if (is_string($name) && $name !== '') {
                    $middlewareFactory = $strapi->get('middlewares')->get($name);
                    if ($middlewareFactory === null) {
                        throw new \RuntimeException("Middleware {$name} not found.");
                    }
                    $middlewares[] = ['name' => $name, 'handler' => self::instantiateMiddleware($middlewareFactory, $name, $itemConfig, $strapi) ?? self::dummyMiddleware()];
                    continue;
                }

                if (is_string($resolve) && $resolve !== '') {
                    $resolvedMiddlewareFactory = self::resolveCustomMiddleware($resolve, $strapi);
                    $middlewares[] = ['name' => $resolve, 'handler' => self::instantiateMiddleware($resolvedMiddlewareFactory, $resolve, $itemConfig, $strapi) ?? self::dummyMiddleware()];
                    continue;
                }

                throw new \RuntimeException('Invalid middleware configuration. Missing name or resolve properties.');
            }

            throw new \RuntimeException('Middleware config must either be a string or an object {name?: string, resolve?: string, config: any}.');
        }

        return $middlewares;
    }

    /** Resolve a middleware factory from a file path (relative to the project root) or a class name. */
    private static function resolveCustomMiddleware(string $resolve, Strapi $strapi): callable
    {
        if (class_exists($resolve)) {
            $instance = new $resolve();
            if (is_callable($instance)) {
                return $instance;
            }
        }

        $modulePath = str_starts_with($resolve, '/') ? $resolve : $strapi->dirs()->root . '/' . $resolve;
        if (!is_file($modulePath) && is_file($modulePath . '.php')) {
            $modulePath .= '.php';
        }

        try {
            $factory = (static fn (): mixed => require $modulePath)();
        } catch (\Throwable) {
            throw new \RuntimeException("Could not load middleware \"{$modulePath}\".");
        }

        if (!is_callable($factory)) {
            throw new \RuntimeException("Could not load middleware \"{$modulePath}\".");
        }

        return $factory;
    }
}
