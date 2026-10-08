<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\Tests\ScopeFactory;
use Strapi\CreateStrapiApp\Utils\ComposerJson;
use Strapi\CreateStrapiApp\Utils\PackageJson;
use Strapi\CreateStrapiApp\Utils\Template;

final class PackageJsonTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/csa-pkg-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Template::removeDirectory($this->dir);
    }

    public function testKebabCase(): void
    {
        self::assertSame('my-strapi-project', PackageJson::kebabCase('my-strapi-project'));
        self::assertSame('my-project-2', PackageJson::kebabCase('MyProject2'));
        self::assertSame('foo-bar', PackageJson::kebabCase('  Foo_Bar  '));
        self::assertSame('xml-http-request', PackageJson::kebabCase('XMLHttpRequest'));
    }

    public function testMergesTheTemplatePackageJsonAndSortsIt(): void
    {
        file_put_contents($this->dir . '/package.json', json_encode(['scripts' => ['start' => 'php bin/strapi start', 'build' => 'php bin/strapi build'], 'dependencies' => ['zod' => '1'], 'custom' => true]));

        PackageJson::createPackageJSON(ScopeFactory::scope([
            'rootPath' => $this->dir,
            'name' => 'My App',
            'dependencies' => ['@strapi/admin' => '5.56.0', 'react' => '^18.0.0'],
            'packageJsonStrapi' => ['template' => 'blog'],
        ]));

        $raw = (string) file_get_contents($this->dir . '/package.json');
        $pkg = json_decode($raw, true);
        self::assertIsArray($pkg);

        self::assertSame(['name', 'version', 'private', 'description', 'scripts', 'dependencies', 'devDependencies', 'engines', 'custom', 'strapi'], array_keys($pkg));
        self::assertSame('my-app', $pkg['name']);
        self::assertSame(['build', 'start'], array_keys($pkg['scripts']));
        self::assertSame(['@strapi/admin', 'react', 'zod'], array_keys($pkg['dependencies']));
        self::assertSame(['template' => 'blog', 'uuid' => 'uuid', 'installId' => 'install-id'], $pkg['strapi']);
        self::assertStringContainsString('"devDependencies": {}', $raw);
        self::assertStringContainsString("\n  \"name\"", $raw, 'two-space indentation');
    }

    public function testWritesPnpmOnlyBuiltDependenciesForOldPnpm(): void
    {
        PackageJson::createPackageJSON(ScopeFactory::scope(['rootPath' => $this->dir, 'packageManager' => 'pnpm', 'pnpmVersion' => '9.0.0']));
        $pkg = json_decode((string) file_get_contents($this->dir . '/package.json'), true);
        self::assertIsArray($pkg);
        self::assertSame(['@swc/core', 'better-sqlite3', 'core-js-pure', 'esbuild', 'sharp'], $pkg['pnpm']['onlyBuiltDependencies']);
    }

    public function testComposerConstraintsFollowVersioning(): void
    {
        self::assertSame('^5.56', ComposerJson::constraint('5.56.0'));
        self::assertSame('^5.56', ComposerJson::constraint('5.56.0.1'));
        self::assertSame('5.57.0-beta.1', ComposerJson::constraint('5.57.0-beta.1'));
        self::assertTrue(ComposerJson::isPreRelease('6.0.0-alpha.1'));
        self::assertFalse(ComposerJson::isPreRelease('5.56.0.2'));
    }

    public function testWritesTheComposerJson(): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['scripts' => ['start' => '@php bin/strapi start']]));

        ComposerJson::createComposerJSON(ScopeFactory::scope([
            'rootPath' => $this->dir,
            'strapiVersion' => '5.57.0-beta.2',
            'composerDependencies' => [...ComposerJson::strapiDependencies('5.57.0-beta.2'), 'ext-pdo_sqlite' => '*'],
        ]));

        $composer = json_decode((string) file_get_contents($this->dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(['description', 'type', 'require', 'scripts', 'config', 'minimum-stability', 'prefer-stable'], array_keys($composer));
        self::assertSame('project', $composer['type']);
        self::assertSame(['php', 'ext-pdo_sqlite'], array_slice(array_keys($composer['require']), 0, 2));
        self::assertSame('5.57.0-beta.2', $composer['require']['strapi/strapi']);
        self::assertSame('5.57.0-beta.2', $composer['require']['strapi/plugin-users-permissions']);
        self::assertSame('beta', $composer['minimum-stability']);
        self::assertTrue($composer['prefer-stable']);
        self::assertSame(0, $composer['config']['process-timeout']);
    }

    public function testAStableReleaseNeedsNoStabilityFlags(): void
    {
        ComposerJson::createComposerJSON(ScopeFactory::scope(['rootPath' => $this->dir]));
        $composer = json_decode((string) file_get_contents($this->dir . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertArrayNotHasKey('minimum-stability', $composer);
        self::assertSame('^5.56', $composer['require']['strapi/strapi']);
    }
}
