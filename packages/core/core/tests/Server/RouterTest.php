<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Server;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Router;
use Strapi\Core\Utils\BootCache;

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

    /** koa-router `:name(regex)` params (the documentation plugin's `/v:major(\\d+).:minor(\\d+).:patch(\\d+)`). */
    public function testRegexRoutesCaptureNamedParams(): void
    {
        $router = new Router('/documentation');
        $router->add('GET', '/v:major(\\d+).:minor(\\d+).:patch(\\d+)', static fn (): null => null, ['handler' => 'documentation.index']);

        $match = $router->match('GET', '/documentation/v1.2.3');
        self::assertIsArray($match);
        self::assertSame(['major' => '1', 'minor' => '2', 'patch' => '3'], $match['params']);
        self::assertNull($router->match('GET', '/documentation/vx.2.3'));
    }

    /** @return list<array{handler: string, params: array<string, string>}|list<string>|null> */
    private static function outcomes(Router $router): array
    {
        $outcomes = [];
        foreach ([['GET', '/api/articles/42'], ['DELETE', '/api/articles/42'], ['PUT', '/api/articles'], ['GET', '/api/v1.2.3'], ['GET', '/api/anything'], ['GET', '/elsewhere'], ['HEAD', '/api/articles/1/']] as [$method, $path]) {
            $match = $router->match($method, $path);
            $outcomes[] = is_array($match) && isset($match['route']) ? ['handler' => (string) $match['route']['handler'], 'params' => $match['params']] : $match;
        }

        return $outcomes;
    }

    private static function router(string $articlePath = '/articles/:id'): Router
    {
        $router = new Router('/api');
        $router->add('GET', $articlePath, static fn (): string => 'find', ['handler' => 'find']);
        $router->add('POST', '/articles', static fn (): string => 'create', ['handler' => 'create']);
        $router->add('GET', '/v:major(\\d+).:minor(\\d+).:patch(\\d+)', static fn (): null => null, ['handler' => 'version']);
        $router->add('ALL', '/((?!uploads/).+)', static fn (): null => null, ['handler' => 'static']);

        return $router;
    }

    /** PHP port: a settled boot keeps the compiled route table in the boot cache (`routes`). */
    public function testTheCompiledTableComesFromTheBootCacheKeyedOnTheRoutes(): void
    {
        $dir = sys_get_temp_dir() . '/strapi-router-cache-' . bin2hex(random_bytes(6));
        $cache = new BootCache($dir);

        try {
            $expected = self::outcomes(self::router());

            $first = self::router();
            $first->useCache($cache);
            self::assertSame($expected, self::outcomes($first));
            $files = glob($dir . '/routes.*.php') ?: [];
            self::assertCount(1, $files, 'written at the first compile');

            // the next boot's router: the same routes, the table read back (handlers stay this boot's)
            $second = self::router();
            $second->useCache($cache);
            self::assertSame($expected, self::outcomes($second));
            $match = $second->match('POST', '/api/articles');
            self::assertIsArray($match);
            self::assertSame('create', $match['handler']());

            // another route: another key, the old table removed
            $changed = self::router('/posts/:id');
            $changed->useCache($cache);
            self::assertSame(self::outcomes(self::router('/posts/:id')), self::outcomes($changed));
            self::assertIsArray($changed->match('GET', '/api/posts/1'));
            $after = glob($dir . '/routes.*.php') ?: [];
            self::assertCount(1, $after);
            self::assertNotSame($files, $after);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}
