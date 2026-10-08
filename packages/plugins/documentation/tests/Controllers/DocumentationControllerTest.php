<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Controllers;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Strapi\Core\Core;
use Strapi\Core\Middlewares\Session;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Controllers\Documentation;
use Strapi\Utils\Errors\ValidationError;

/**
 * PHP-port tests (upstream has no controller tests): server/src/controllers/documentation.ts run
 * against `examples/getstarted` booted on an in-memory SQLite database, which loads this plugin
 * and generates the current version's `full_documentation.json` in bootstrap.
 */
final class DocumentationControllerTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';

        self::$strapi = Core::createStrapi(['appDir' => dirname(__DIR__, 5) . '/examples/getstarted'])->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private static function controller(): Documentation
    {
        $controller = self::strapi()->plugin('documentation')->controller('documentation');
        self::assertInstanceOf(Documentation::class, $controller);

        return $controller;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $params
     */
    private static function ctx(string $method = 'GET', ?array $body = null, array $query = [], array $params = []): Context
    {
        $request = new ServerRequest($method, 'http://localhost/documentation');
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $ctx = new Context($request, ['keys' => ['k1']]);
        if ($query !== []) {
            $ctx->setQuery($query);
        }
        if ($params !== []) {
            $ctx->setParams($params);
        }

        return $ctx;
    }

    private static function bodyString(Context $ctx): string
    {
        $body = $ctx->body();
        self::assertInstanceOf(StreamInterface::class, $body);

        return (string) $body;
    }

    private static function currentVersion(): string
    {
        $version = self::strapi()->plugin('documentation')->service('documentation')->getDocumentationVersion();
        self::assertIsString($version);

        return $version;
    }

    public function testGetInfosListsTheGeneratedVersion(): void
    {
        $ctx = self::ctx();
        self::controller()->getInfos($ctx);

        $body = $ctx->body();
        self::assertIsArray($body);
        self::assertSame(self::currentVersion(), $body['currentVersion']);
        self::assertSame('/documentation', $body['prefix']);
        self::assertContains(self::currentVersion(), array_column($body['docVersions'], 'version'));
        self::assertSame(['restrictedAccess' => false], $body['documentationAccess']);
    }

    public function testIndexServesSwaggerUiWithTheSpec(): void
    {
        $ctx = self::ctx();
        self::controller()->index($ctx, static fn () => null);

        self::assertSame(200, $ctx->status());
        self::assertSame('max-age=0', $ctx->responseHeader('Cache-Control'));
        $html = self::bodyString($ctx);
        self::assertStringContainsString('SwaggerUIBundle', $html);
        self::assertStringContainsString('"openapi":"3.0.0"', $html);
        self::assertStringNotContainsString('<%=', $html);
    }

    public function testIndexServesARequestedVersion(): void
    {
        [$major, $minor, $patch] = explode('.', self::currentVersion());
        $ctx = self::ctx(params: ['major' => $major, 'minor' => $minor, 'patch' => $patch]);
        self::controller()->index($ctx, static fn () => null);

        self::assertSame(200, $ctx->status());
    }

    public function testLoginViewShowsTheErrorText(): void
    {
        $ctx = self::ctx(query: ['error' => 'password']);
        self::controller()->loginView($ctx, static fn () => null);
        self::assertStringContainsString('Wrong password...', self::bodyString($ctx));

        $clean = self::ctx();
        self::controller()->loginView($clean, static fn () => null);
        $html = self::bodyString($clean);
        self::assertStringNotContainsString('Wrong password...', $html);
        self::assertStringContainsString('/documentation/login', $html);
    }

    public function testUpdateSettingsRequiresAStrongPasswordWhenRestricted(): void
    {
        try {
            self::controller()->updateSettings(self::ctx('PUT', ['restrictedAccess' => true]));
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('password is required', $e->getMessage());
        }

        try {
            self::controller()->updateSettings(self::ctx('PUT', ['restrictedAccess' => true, 'password' => 'abcdefgh1']));
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('password must contain at least one uppercase character', $e->getMessage());
        }
    }

    public function testUpdateSettingsThenLogin(): void
    {
        $ctx = self::ctx('PUT', ['restrictedAccess' => true, 'password' => 'Secret123']);
        self::controller()->updateSettings($ctx);
        self::assertSame(['ok' => true], $ctx->body());

        $config = self::strapi()->store()->get(['type' => 'plugin', 'name' => 'documentation', 'key' => 'config']);
        self::assertIsArray($config);
        self::assertTrue($config['restrictedAccess']);
        self::assertIsString($config['password']);
        self::assertStringStartsWith('$2y$10$', $config['password']);
        self::assertTrue(password_verify('Secret123', $config['password']));

        $serverUrl = (string) self::strapi()->config()->get('server.url');

        $wrong = self::ctx('POST', ['password' => 'nope']);
        $wrong->state()->set(Session::STATE_KEY, []);
        self::controller()->login($wrong);
        self::assertSame(302, $wrong->status());
        self::assertSame($serverUrl . '/documentation?error=password', $wrong->responseHeader('Location'));
        self::assertSame([], $wrong->state()->get(Session::STATE_KEY));

        $right = self::ctx('POST', ['password' => 'Secret123']);
        $right->state()->set(Session::STATE_KEY, []);
        self::controller()->login($right);
        self::assertSame($serverUrl . '/documentation', $right->responseHeader('Location'));
        self::assertSame(['documentation' => ['logged' => true]], $right->state()->get(Session::STATE_KEY));

        // turning the restriction off keeps no password
        $off = self::ctx('PUT', ['restrictedAccess' => false]);
        self::controller()->updateSettings($off);
        self::assertSame(
            ['restrictedAccess' => false],
            self::strapi()->store()->get(['type' => 'plugin', 'name' => 'documentation', 'key' => 'config']),
        );
    }

    public function testRegenerateAndDeleteRejectUnknownVersions(): void
    {
        $missing = self::ctx('POST', []);
        self::controller()->regenerateDoc($missing);
        self::assertSame(400, $missing->status());
        self::assertIsArray($missing->body());
        self::assertSame('Please provide a version.', $missing->body()['error']['message']);

        $unknown = self::ctx('POST', ['version' => '99.0.0']);
        self::controller()->regenerateDoc($unknown);
        self::assertSame(400, $unknown->status());
        self::assertSame('The version you are trying to generate does not exist.', $unknown->body()['error']['message']);

        $delete = self::ctx('DELETE', params: ['version' => '99.0.0']);
        self::controller()->deleteDoc($delete);
        self::assertSame(400, $delete->status());
        self::assertSame('The version you are trying to delete does not exist.', $delete->body()['error']['message']);
    }

    public function testRegenerateRewritesTheCurrentVersion(): void
    {
        $ctx = self::ctx('POST', ['version' => self::currentVersion()]);
        self::controller()->regenerateDoc($ctx);
        self::assertSame(['ok' => true], $ctx->body());

        $file = self::strapi()->dirs()->extensions . '/documentation/documentation/' . self::currentVersion() . '/full_documentation.json';
        $doc = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($doc);
        self::assertSame('3.0.0', $doc['openapi']);
        self::assertSame(self::currentVersion(), $doc['info']['version']);
    }
}
