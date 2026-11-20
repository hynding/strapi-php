<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Openapi\Exports;
use Strapi\Utils\ContentApiRouteParams;
use Strapi\Utils\Zod\ZodLazy;

/**
 * PHP port: what a settled boot (`Strapi::bootstrapSettled()`; under PHP-FPM, most requests)
 * defers, it must not change. Endpoints are composed when their route first matches, the core
 * routes' request and response schemas are built on first use, and admin actions are not
 * validated again: the responses, the OpenAPI document and the registered actions are those of an
 * unsettled boot. examples/getstarted in production, twice on one SQLite file.
 */
final class SettledRoutesTest extends TestCase
{
    private string $database = '';

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> before the test: getenv(), $_ENV, $_SERVER */
    private array $saved = [];

    private static function appDir(): string
    {
        return dirname(__DIR__, 4) . '/examples/getstarted';
    }

    protected function setUp(): void
    {
        $this->database = sys_get_temp_dir() . '/strapi-settled-routes-' . bin2hex(random_bytes(6)) . '.db';
        $env = [
            'NODE_ENV' => 'production',
            'DATABASE_CLIENT' => 'sqlite',
            'DATABASE_FILENAME' => $this->database,
            'STRAPI_NO_EXIT' => '1',
            'LOG_LEVEL' => 'error',
            'JWT_SECRET' => 'settled-routes-test',
            'ADMIN_JWT_SECRET' => 'settled-routes-admin',
            'APP_KEYS' => 'settled-a,settled-b',
            'API_TOKEN_SALT' => 'settled-salt',
            'TRANSFER_TOKEN_SALT' => 'settled-transfer',
            'ENCRYPTION_KEY' => 'settled-routes-encryption',
        ];
        foreach ($env as $name => $value) {
            $this->saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$getenv, $env, $server]) {
            putenv(is_string($getenv) ? "{$name}={$getenv}" : $name);
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        @unlink($this->database);
        @unlink(self::appDir() . '/.tmp/bootstrap.settled');
    }

    private static function boot(): Strapi
    {
        return Core::createStrapi(['appDir' => self::appDir()])->load();
    }

    /** @return array<string, array{0: int, 1: string}> status and body of each request */
    private static function responses(Strapi $strapi): array
    {
        $server = $strapi->server()->mount();
        $responses = [];
        foreach ([
            ['GET', '/_health', null],
            ['GET', '/admin/init', null],
            ['GET', '/api/articles', null],
            ['GET', '/api/articles/abc', null],
            ['GET', '/api/nope', null],
            ['PATCH', '/api/articles', null],
            ['POST', '/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong']],
            ['POST', '/api/auth/local', ['identifier' => 'nobody', 'password' => 'wrong']],
            ['GET', '/upload/files', null],
        ] as [$method, $path, $json]) {
            $request = new ServerRequest($method, $path, $json !== null ? ['Content-Type' => 'application/json'] : [], $json !== null ? (string) json_encode($json) : null);
            $response = $server->handle($request);
            $responses["{$method} {$path}"] = [$response->getStatusCode(), (string) $response->getBody()];
        }

        return $responses;
    }

    /** @return list<string> */
    private static function actions(Strapi $strapi): array
    {
        $permission = $strapi->service('admin::permission');
        self::assertInstanceOf(\Strapi\Admin\Services\Permission::class, $permission);

        return $permission->actionProvider->keys();
    }

    /**
     * What requests read of the article routes' schemas: the extra query and body keys, and what
     * the extra params' validators make of a bad and a good value.
     *
     * @return list<array<string, mixed>>
     */
    private static function routeParams(Strapi $strapi): array
    {
        $out = [];
        foreach ($strapi->api('article')->routes()['article']['routes'] ?? [] as $route) {
            $shape = ContentApiRouteParams::routeBodyShape($route) ?? [];
            $query = ContentApiRouteParams::routeQuery($route) ?? [];
            $out[] = [
                'route' => $route['method'] . ' ' . $route['path'],
                'queryKeys' => ContentApiRouteParams::getExtraQueryKeysFromRoute($route),
                'bodyKeys' => ContentApiRouteParams::getExtraRootKeysFromRouteBody($route),
                'extraParam' => isset($query['extraParam']) ? [ContentApiRouteParams::runValidator($query['extraParam'], 5)[0], ContentApiRouteParams::runValidator($query['extraParam'], 'ok')] : null,
                'clientMutationId' => isset($shape['clientMutationId']) ? [ContentApiRouteParams::runValidator($shape['clientMutationId'], 5)[0], ContentApiRouteParams::runValidator($shape['clientMutationId'], 'ok')] : null,
                'locale' => isset($query['locale']) ? ContentApiRouteParams::runValidator($query['locale'], 'en')[0] : null,
            ];
        }

        return $out;
    }

    /** The content API's OpenAPI document, without what changes at every generation. */
    private static function openapi(Strapi $strapi): string
    {
        $document = Exports::generate($strapi);
        unset($document['durationMs']);
        $json = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // `publishedAt`'s default is the generation time
        return (string) preg_replace('/"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z"/', '"<now>"', $json);
    }

    public function testASettledBootServesWhatAnUnsettledOneServes(): void
    {
        $strapi = self::boot();
        self::assertFalse($strapi->bootstrapSettled(), 'a new database');
        $responses = self::responses($strapi);
        $actions = self::actions($strapi);
        $openapi = self::openapi($strapi);
        $routeParams = self::routeParams($strapi);
        $create = array_values(array_filter($routeParams, static fn (array $r): bool => $r['route'] === 'POST /articles'))[0] ?? null;
        self::assertIsArray($create);
        self::assertContains('clientMutationId', $create['bodyKeys'], 'addInputParams joined the body');
        $find = $strapi->api('article')->routes()['article']['routes'][0] ?? null;
        self::assertIsArray($find);
        self::assertNotInstanceOf(ZodLazy::class, $find['response']);
        $strapi->destroy();

        $strapi = self::boot();
        self::assertTrue($strapi->bootstrapSettled(), 'same inputs, same database');

        // deferred: the schemas stand in as z.lazy(), and nothing has built them yet
        $find = $strapi->api('article')->routes()['article']['routes'][0] ?? null;
        self::assertIsArray($find);
        self::assertSame('/articles', $find['path']);
        self::assertInstanceOf(ZodLazy::class, $find['response']);
        // the core params keep their names; src/index.php's contentAPI.addQueryParams() joins them
        $query = $find['request']['query'];
        self::assertSame(['fields', 'filters', '_q', 'pagination', 'sort', 'populate', 'locale', 'status', 'publicationFilter', 'hasPublishedVersion', 'extraParam'], array_keys($query));
        unset($query['extraParam']);
        self::assertContainsOnlyInstancesOf(ZodLazy::class, $query);

        self::assertSame($routeParams, self::routeParams($strapi), 'requests read the same params, and they validate the same');
        self::assertSame($responses, self::responses($strapi));
        self::assertSame($actions, self::actions($strapi));
        // the deferred schemas are built before the document is assembled, in registration order
        self::assertSame($openapi, self::openapi($strapi));
        $strapi->destroy();
    }
}
