<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Server;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Services\Server\Openapi;
use Strapi\Types\Core\StrapiDirectories;

/**
 * Port of packages/core/core/src/services/server/__tests__/openapi.test.ts. Upstream mocks
 * `fs-extra` and `@strapi/openapi`; here the cache lives in a temporary directory and
 * `Openapi::$generate` stands in for `generate()`.
 */
final class OpenapiTest extends TestCase
{
    private string $root;

    /** @var list<array<string, mixed>> */
    private array $registeredRouters = [];

    /** @var list<array{type: string}> */
    private array $generateCalls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/strapi-openapi-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->registeredRouters = [];
        $this->generateCalls = [];
    }

    protected function tearDown(): void
    {
        Openapi::$generate = null;
        self::rmrf($this->root);
    }

    private static function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::rmrf("{$path}/{$entry}");
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $openapiConfig
     * @param array{apiPrefix?: string, adminPath?: string} $options
     */
    private function createStrapiMock(array $openapiConfig = [], array $options = []): object
    {
        $apiPrefix = $options['apiPrefix'] ?? '/api';
        $adminPath = $options['adminPath'] ?? '/admin';
        $test = $this;

        $config = new class ($openapiConfig, $apiPrefix, $adminPath) {
            /** @param array<string, mixed> $openapiConfig */
            public function __construct(private readonly array $openapiConfig, private readonly string $apiPrefix, private readonly string $adminPath)
            {
            }

            public function get(string $key, mixed $defaultValue = null): mixed
            {
                return match ($key) {
                    'server.openapi' => $this->openapiConfig,
                    'api.rest.prefix' => $this->apiPrefix,
                    'admin.path' => $this->adminPath,
                    default => $defaultValue,
                };
            }
        };

        $server = new class ($test) {
            public function __construct(private readonly OpenapiTest $test)
            {
            }

            /** @param array<string, mixed> $router */
            public function routes(array $router): self
            {
                $this->test->recordRouter($router);

                return $this;
            }
        };

        $logger = new class () {
            /** @var list<string> */
            public array $errors = [];

            /** @param array<string, mixed> $context */
            public function error(string $message, array $context = []): void
            {
                $this->errors[] = $message;
            }
        };

        $dirs = StrapiDirectories::fromRoot($this->root);

        return new class ($config, $server, $logger, $dirs) {
            public function __construct(private readonly object $config, private readonly object $server, private readonly object $logger, private readonly StrapiDirectories $dirs)
            {
            }

            public function config(): object
            {
                return $this->config;
            }

            public function server(): object
            {
                return $this->server;
            }

            public function log(): object
            {
                return $this->logger;
            }

            public function dirs(): StrapiDirectories
            {
                return $this->dirs;
            }
        };
    }

    /** @param array<string, mixed> $router */
    public function recordRouter(array $router): void
    {
        $this->registeredRouters[] = $router;
    }

    /** @param array<string, mixed> $document */
    private function mockGenerate(array $document): void
    {
        Openapi::$generate = function (object $strapi, array $options) use ($document): array {
            $this->generateCalls[] = $options;

            return ['document' => $document, 'durationMs' => 1];
        };
    }

    private static function ctx(): Context
    {
        return new Context(new ServerRequest('GET', 'http://localhost/api/openapi.json'));
    }

    public function testDoesNotRegisterRouteWhenDisabled(): void
    {
        Openapi::registerOpenAPIRoute($this->createStrapiMock([]));

        self::assertSame([], $this->registeredRouters);
    }

    public function testRegistersOneRoutePerConfiguredEndpoint(): void
    {
        Openapi::registerOpenAPIRoute($this->createStrapiMock([
            'content-api' => [
                'access' => 'public',
                'route' => [
                    'path' => '/spec.json',
                ],
            ],
            'admin' => [
                'access' => 'authenticated',
            ],
        ]));

        self::assertCount(2, $this->registeredRouters);
        [$contentApiRouter, $adminRouter] = $this->registeredRouters;

        self::assertSame('content-api', $contentApiRouter['type']);
        self::assertCount(1, $contentApiRouter['routes']);
        self::assertSame('GET', $contentApiRouter['routes'][0]['method']);
        self::assertSame('/spec.json', $contentApiRouter['routes'][0]['path']);
        self::assertFalse($contentApiRouter['routes'][0]['config']['auth']);

        self::assertSame('admin', $adminRouter['type']);
        self::assertSame('/admin', $adminRouter['prefix']);
        self::assertCount(1, $adminRouter['routes']);
        self::assertSame('GET', $adminRouter['routes'][0]['method']);
        self::assertSame('/openapi.json', $adminRouter['routes'][0]['path']);
        // admin endpoint requires an authenticated admin
        self::assertSame(['admin::isAuthenticatedAdmin'], $adminRouter['routes'][0]['config']['policies']);
        self::assertArrayNotHasKey('auth', $adminRouter['routes'][0]['config']);
    }

    public function testContentApiPublicAccessDisablesAuth(): void
    {
        Openapi::registerOpenAPIRoute($this->createStrapiMock(['content-api' => ['access' => 'public']]));

        self::assertCount(1, $this->registeredRouters);
        self::assertFalse($this->registeredRouters[0]['routes'][0]['config']['auth']);
    }

    public function testThrowsWhenTheContentApiEndpointIsConfiguredAsAuthenticated(): void
    {
        $this->expectExceptionMessage('Invalid OpenAPI access "authenticated" for "content-api". Expected one of: disabled, public');

        Openapi::registerOpenAPIRoute($this->createStrapiMock(['content-api' => ['access' => 'authenticated']]));
    }

    public function testAdminAuthenticatedAccessRequiresAnAuthenticatedAdmin(): void
    {
        Openapi::registerOpenAPIRoute($this->createStrapiMock(['admin' => ['access' => 'authenticated']]));

        self::assertCount(1, $this->registeredRouters);
        self::assertSame(['admin::isAuthenticatedAdmin'], $this->registeredRouters[0]['routes'][0]['config']['policies']);
        self::assertArrayNotHasKey('auth', $this->registeredRouters[0]['routes'][0]['config']);
    }

    public function testThrowsWhenTheAdminEndpointIsConfiguredAsPublic(): void
    {
        $this->expectExceptionMessage('Invalid OpenAPI access "public" for "admin". Expected one of: disabled, authenticated');

        Openapi::registerOpenAPIRoute($this->createStrapiMock(['admin' => ['access' => 'public']]));
    }

    public function testServesFreshFileCacheWhenAvailable(): void
    {
        $document = ['openapi' => '3.1.0', 'info' => ['title' => 'Cached']];
        mkdir("{$this->root}/.strapi/openapi", 0777, true);
        file_put_contents("{$this->root}/.strapi/openapi/openapi.json", (string) json_encode($document));
        $this->mockGenerate(['openapi' => '3.1.0']);

        Openapi::registerOpenAPIRoute($this->createStrapiMock([
            'content-api' => [
                'access' => 'public',
                'cache' => [
                    'enabled' => true,
                    'maxAgeMs' => 60_000,
                    'filePath' => '.strapi/openapi/openapi.json',
                ],
            ],
        ]));

        $ctx = self::ctx();
        ($this->registeredRouters[0]['routes'][0]['handler'])($ctx);

        self::assertSame([], $this->generateCalls);
        self::assertEquals($document, json_decode((string) json_encode($ctx->body()), true));
        self::assertSame('application/json', $ctx->responseHeader('Content-Type'));
    }

    public function testRegeneratesAndWritesCacheWhenCacheIsStale(): void
    {
        $document = ['openapi' => '3.1.0', 'info' => ['title' => 'Generated', 'version' => '1.0.0']];
        mkdir("{$this->root}/.strapi/openapi", 0777, true);
        $cacheFile = "{$this->root}/.strapi/openapi/openapi.json";
        file_put_contents($cacheFile, '{"openapi":"stale"}');
        touch($cacheFile, time() - 10);
        $this->mockGenerate($document);

        Openapi::registerOpenAPIRoute($this->createStrapiMock([
            'content-api' => [
                'access' => 'public',
                'cache' => [
                    'enabled' => true,
                    'maxAgeMs' => 50,
                    'filePath' => '.strapi/openapi/openapi.json',
                ],
            ],
        ]));

        $ctx = self::ctx();
        ($this->registeredRouters[0]['routes'][0]['handler'])($ctx);

        self::assertCount(1, $this->generateCalls);
        self::assertSame($document, json_decode((string) file_get_contents($cacheFile), true));
        self::assertSame($document, $ctx->body());
    }

    public function testDoesNotExposeAdminRouteWhenAdminEndpointIsDisabled(): void
    {
        Openapi::registerOpenAPIRoute($this->createStrapiMock([
            'content-api' => ['access' => 'public'],
            'admin' => ['access' => 'disabled'],
        ]));

        self::assertCount(1, $this->registeredRouters);
        self::assertSame('content-api', $this->registeredRouters[0]['type']);
    }

    public function testSupportsAdminPathsThatIncludeAdminPrefixWithoutDoublePrefixing(): void
    {
        Openapi::registerOpenAPIRoute($this->createStrapiMock([
            'admin' => [
                'access' => 'authenticated',
                'route' => [
                    'path' => '/admin/spec.json',
                ],
            ],
        ]));

        self::assertCount(1, $this->registeredRouters);
        self::assertSame('admin', $this->registeredRouters[0]['type']);
        self::assertSame('/admin', $this->registeredRouters[0]['prefix']);
        self::assertSame('/spec.json', $this->registeredRouters[0]['routes'][0]['path']);
    }

    public function testThrowsWhenConfiguredEndpointsResolveToTheSamePath(): void
    {
        $this->expectExceptionMessage('Duplicate OpenAPI endpoint path detected: "/api/openapi.json"');

        Openapi::registerOpenAPIRoute($this->createStrapiMock(
            [
                'content-api' => ['access' => 'public', 'route' => ['path' => '/openapi.json']],
                'admin' => ['access' => 'authenticated'],
            ],
            ['adminPath' => '/api'],
        ));
    }

    public function testThrowsForUnsupportedAccessValues(): void
    {
        $this->expectExceptionMessage('Invalid OpenAPI access "unknown-access" for "admin". Expected one of: disabled, authenticated');

        Openapi::registerOpenAPIRoute($this->createStrapiMock(['admin' => ['access' => 'unknown-access']]));
    }
}
