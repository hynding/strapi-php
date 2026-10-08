<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Routes;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Routes\Providers\PluginRoutesProvider;
use Strapi\Openapi\Tests\Fixtures\Routes;
use Strapi\Openapi\Tests\Mocks\StrapiMock;

/** Port of __tests__/routes/plugins-route-provider.test.ts. */
final class PluginsRouteProviderTest extends TestCase
{
    public function testShouldReturnOnlyContentApiRoutes(): void
    {
        $provider = new PluginRoutesProvider(new StrapiMock());

        self::assertCount(count(Routes::test()) + count(Routes::foobar()), $provider->routes());
    }

    public function testShouldPrependRouterPrefixToRoutePaths(): void
    {
        $strapiMock = StrapiMock::withPlugins([
            'upload' => [
                'content-api' => [
                    'type' => 'content-api',
                    'prefix' => '/upload',
                    'routes' => [
                        ['method' => 'GET', 'path' => '/', 'handler' => 'controller.find', 'info' => ['type' => 'content-api']],
                        ['method' => 'GET', 'path' => '/files/:id', 'handler' => 'controller.findOne', 'info' => ['type' => 'content-api']],
                    ],
                ],
            ],
        ]);

        $routes = (new PluginRoutesProvider($strapiMock))->routes();

        self::assertCount(2, $routes);
        self::assertSame('/upload', $routes[0]['path']);
        self::assertSame('/upload/files/:id', $routes[1]['path']);
    }

    public function testShouldUseRouteConfigPrefixInsteadOfRouterPrefixWhenPresent(): void
    {
        $strapiMock = StrapiMock::withPlugins([
            'users-permissions' => [
                'content-api' => [
                    'type' => 'content-api',
                    'prefix' => '/users-permissions',
                    'routes' => [
                        ['method' => 'POST', 'path' => '/auth/local', 'handler' => 'auth.callback', 'info' => ['type' => 'content-api'], 'config' => ['prefix' => '']],
                        ['method' => 'GET', 'path' => '/users/me', 'handler' => 'user.me', 'info' => ['type' => 'content-api'], 'config' => ['prefix' => '']],
                    ],
                ],
            ],
        ]);

        $routes = (new PluginRoutesProvider($strapiMock))->routes();

        self::assertCount(2, $routes);
        // These routes have config.prefix = '', so they bypass the router prefix
        self::assertSame('/auth/local', $routes[0]['path']);
        self::assertSame('/users/me', $routes[1]['path']);
    }

    public function testShouldHandleMixOfRoutesWithAndWithoutConfigPrefix(): void
    {
        $strapiMock = StrapiMock::withPlugins([
            'users-permissions' => [
                'content-api' => [
                    'type' => 'content-api',
                    'prefix' => '/users-permissions',
                    'routes' => [
                        ['method' => 'POST', 'path' => '/auth/local', 'handler' => 'auth.callback', 'info' => ['type' => 'content-api'], 'config' => ['prefix' => '']],
                        ['method' => 'GET', 'path' => '/roles', 'handler' => 'role.find', 'info' => ['type' => 'content-api']],
                    ],
                ],
            ],
        ]);

        $routes = (new PluginRoutesProvider($strapiMock))->routes();

        self::assertCount(2, $routes);
        // config.prefix = '' bypasses router prefix
        self::assertSame('/auth/local', $routes[0]['path']);
        // No config.prefix, uses router prefix
        self::assertSame('/users-permissions/roles', $routes[1]['path']);
    }

    public function testShouldHandleRoutesWithNoRouterPrefix(): void
    {
        $strapiMock = StrapiMock::withPlugins([
            'test' => [
                'content-api' => [
                    'type' => 'content-api',
                    'routes' => [
                        ['method' => 'GET', 'path' => '/items', 'handler' => 'controller.find', 'info' => ['type' => 'content-api']],
                    ],
                ],
            ],
        ]);

        $routes = (new PluginRoutesProvider($strapiMock))->routes();

        self::assertCount(1, $routes);
        self::assertSame('/items', $routes[0]['path']);
    }

    public function testShouldBeIterable(): void
    {
        $provider = new PluginRoutesProvider(new StrapiMock());

        self::assertCount(count(Routes::test()) + count(Routes::foobar()), iterator_to_array($provider, false));
    }
}
