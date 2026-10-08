<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Node;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Utils\Logger;
use Strapi\Cli\Node\Core\EnsureAdminDependencies;
use Strapi\Cli\Node\CreateBuildContext;
use Strapi\Cli\Node\Vite\Config;
use Strapi\Cli\Strapi;

/** Port of packages/core/strapi/src/node/__tests__/create-build-context.test.ts plus the version check of VERSIONING.md. */
final class BuildContextTest extends TestCase
{
    private static string $appDir;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = dirname(__DIR__, 5) . '/examples/getstarted';
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';
    }

    public function testCreatesTheBuildContextOfTheExampleProject(): void
    {
        $ctx = CreateBuildContext::createBuildContext(['cwd' => self::$appDir, 'logger' => Logger::createLogger(['silent' => true]), 'options' => ['bundler' => 'vite', 'minify' => false]]);

        self::assertSame(self::$appDir, $ctx['appDir']);
        self::assertSame('/admin', $ctx['adminPath']);
        self::assertSame('/admin', $ctx['basePath']);
        self::assertSame('vite', $ctx['bundler']);
        self::assertSame('build', $ctx['distDir']);
        self::assertSame(self::$appDir . '/build', $ctx['distPath']);
        self::assertSame('.strapi/client/app.js', $ctx['entry']);
        self::assertSame(self::$appDir . '/.strapi/client', $ctx['runtimeDir']);
        self::assertSame('/admin', $ctx['env']['ADMIN_PATH']);
        self::assertSame('/', $ctx['env']['STRAPI_ADMIN_BACKEND_URL'], 'same origin → the server path');
        self::assertSame('true', $ctx['env']['STRAPI_TELEMETRY_DISABLED']);
        self::assertSame(['minify' => false], $ctx['options']);
        self::assertSame([], $ctx['plugins'], 'no admin plugin is installed in node_modules');
        self::assertFalse($ctx['nextDesignSystem']);
        self::assertSame(CreateBuildContext::DEFAULT_BROWSERSLIST, $ctx['target']);

        $vite = Config::resolveProductionConfig($ctx);
        self::assertStringContainsString("const basePath = \"/admin\";", $vite);
        self::assertStringContainsString("minify: false", $vite);
        self::assertStringContainsString("name: 'strapi/server/build-files'", $vite);
        self::assertStringContainsString('"STRAPI_ADMIN_BACKEND_URL":"/"', $vite);

        $ctx['strapi']->destroy();
    }

    public function testPackageJsonMustPinTheComposerVersion(): void
    {
        EnsureAdminDependencies::assertVersionsMatch(['@strapi/admin' => Strapi::upstreamVersion(), 'react' => '^18']);
        EnsureAdminDependencies::assertVersionsMatch([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match the backend exactly');
        EnsureAdminDependencies::assertVersionsMatch(['@strapi/admin' => '5.0.0']);
    }

    public function testExampleProjectPinsTheMatchingAdminVersion(): void
    {
        $packageJson = json_decode((string) file_get_contents(self::$appDir . '/package.json'), true);

        self::assertSame(Strapi::upstreamVersion(), $packageJson['dependencies']['@strapi/admin']);
        self::assertSame(Strapi::upstreamVersion(), $packageJson['dependencies']['@strapi/strapi']);
    }

    public function testUpstreamVersionDropsThePhpOnlyParts(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Strapi::upstreamVersion());
        self::assertStringStartsWith(Strapi::upstreamVersion(), Strapi::version());
    }
}
