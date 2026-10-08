<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Mocks;

use Strapi\Openapi\Tests\Fixtures\Routes;

/**
 * Port of __tests__/mocks/strapi.mock.ts. Plugins can expose routes either as a route list or as
 * `Record<string, { routes }>`.
 */
final class StrapiMock
{
    /** @return array<string, object> */
    public function apis(): array
    {
        return [
            'api-a' => self::module(['router-a' => ['routes' => Routes::test()]]),
            'api-b' => self::module(['router-b' => ['routes' => Routes::foobar()]]),
        ];
    }

    /** @return array<string, object> */
    public function plugins(): array
    {
        return [
            'plugin-a' => self::module(Routes::test()),
            'plugin-b' => self::module(['router-a' => ['routes' => Routes::foobar()]]),
        ];
    }

    /**
     * A module exposing `routes()`.
     *
     * @param array<array-key, mixed> $routes
     */
    public static function module(array $routes): object
    {
        return new class ($routes) {
            /** @param array<array-key, mixed> $routes */
            public function __construct(private readonly array $routes)
            {
            }

            /** @return array<array-key, mixed> */
            public function routes(): array
            {
                return $this->routes;
            }
        };
    }

    /**
     * A strapi stand-in exposing `plugins()` (upstream: `{ plugins: {...} } as Core.Strapi`).
     *
     * @param array<string, array<array-key, mixed>> $pluginRoutes plugin name => routes
     */
    public static function withPlugins(array $pluginRoutes): object
    {
        $plugins = array_map(static fn (array $routes): object => self::module($routes), $pluginRoutes);

        return new class ($plugins) {
            /** @param array<string, object> $plugins */
            public function __construct(private readonly array $plugins)
            {
            }

            /** @return array<string, object> */
            public function plugins(): array
            {
                return $this->plugins;
            }
        };
    }
}
