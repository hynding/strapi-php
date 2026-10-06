<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Server;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Router;

final class RouterTest extends TestCase
{
    public function testConvertsKoaPathsToFastRoute(): void
    {
        self::assertSame('/articles/{id}', Router::toFastRoute('/articles/:id'));
        self::assertSame('/admin[/{path:.*}]', Router::toFastRoute('/admin/:path*'));
        self::assertSame('/files[/{name}]', Router::toFastRoute('/files/:name?'));
        self::assertSame('/a/{b}/c/{d}', Router::toFastRoute('/a/:b/c/:d'));
    }

    public function testJoinPath(): void
    {
        self::assertSame('/api/articles', Router::joinPath('/api', '/articles'));
        self::assertSame('/api/articles', Router::joinPath('/api/', 'articles/'));
        self::assertSame('/', Router::joinPath('', '/'));
        self::assertSame('/((?!uploads/).+)', Router::joinPath('', '/((?!uploads/).+)'));
    }

    public function testMatchesParamsAllowedMethodsAndRegexRoutes(): void
    {
        $router = new Router('/api');
        $handler = static fn (): string => 'ok';
        $router->add('GET', '/articles/:id', $handler, ['handler' => 'find']);
        $router->add('POST', '/articles', $handler);
        $router->add('GET', '/((?!uploads/).+)', $handler, ['handler' => 'static']);

        $match = $router->match('GET', '/api/articles/42');
        self::assertIsArray($match);
        self::assertSame(['id' => '42'], $match['params']);
        self::assertSame('find', $match['route']['handler']);

        self::assertSame(['GET'], $router->match('DELETE', '/api/articles/42'));
        self::assertSame(['POST', 'GET'], $router->match('PUT', '/api/articles'));

        $static = $router->match('GET', '/api/anything/else');
        self::assertIsArray($static);
        self::assertSame('static', $static['route']['handler']);

        self::assertNull($router->match('GET', '/elsewhere'));

        // HEAD falls back to GET
        $head = $router->match('HEAD', '/api/articles/1');
        self::assertIsArray($head);
    }

    public function testWildcardMatchesTheBareMountPath(): void
    {
        $router = new Router('');
        $router->add('GET', '/admin/:path*', static fn (): null => null, ['handler' => 'admin']);

        self::assertIsArray($router->match('GET', '/admin'));
        self::assertIsArray($router->match('GET', '/admin/'));
        $deep = $router->match('GET', '/admin/content-manager/collection-types');
        self::assertIsArray($deep);
        self::assertSame('content-manager/collection-types', $deep['params']['path']);
    }
}
