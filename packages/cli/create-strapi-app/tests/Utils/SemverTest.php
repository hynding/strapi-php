<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\Utils\PnpmConfig;
use Strapi\CreateStrapiApp\Utils\Semver;
use Strapi\CreateStrapiApp\Tests\ScopeFactory;

final class SemverTest extends TestCase
{
    public function testCoerce(): void
    {
        self::assertSame('10.9.2', Semver::coerce('10.9.2'));
        self::assertSame('20.0.0', Semver::coerce('v20'));
        self::assertSame('4.1.0', Semver::coerce('yarn 4.1'));
        self::assertNull(Semver::coerce('none'));
    }

    public function testSatisfies(): void
    {
        $engine = '>=20.0.0 <=26.x.x';
        self::assertTrue(Semver::satisfies('20.0.0', $engine));
        self::assertTrue(Semver::satisfies('26.9.1', $engine));
        self::assertFalse(Semver::satisfies('27.0.0', $engine));
        self::assertFalse(Semver::satisfies('18.20.0', $engine));
        self::assertTrue(Semver::satisfies('1.22.22', '<4'));
        self::assertFalse(Semver::satisfies('4.0.0', '<4'));
        self::assertTrue(Semver::satisfies('4.12.0', '>=4'));
        self::assertTrue(Semver::satisfies('8.3.6', '>=8.3'));
        self::assertTrue(Semver::satisfies('4.5.0', '4'));
        self::assertFalse(Semver::satisfies('5.0.0', '4'));
        self::assertTrue(Semver::satisfies('0.0.1', '*'));
    }

    public function testPnpmWorkspaceConfig(): void
    {
        self::assertTrue(PnpmConfig::shouldUsePnpmWorkspaceConfig(null));
        self::assertTrue(PnpmConfig::shouldUsePnpmWorkspaceConfig('10.26.0'));
        self::assertFalse(PnpmConfig::shouldUsePnpmWorkspaceConfig('10.25.9'));
        self::assertTrue(PnpmConfig::shouldUsePackageJsonPnpmConfig('9.15.0'));
        self::assertFalse(PnpmConfig::shouldUsePackageJsonPnpmConfig(null));

        $yaml = PnpmConfig::formatPnpmWorkspaceYaml(ScopeFactory::scope(), '11.1.0');
        self::assertSame(
            "packages:\n  - '.'\n\n# Allow day-0 @strapi/* npm publishes while keeping pnpm 11 supply-chain defaults for other deps.\nminimumReleaseAgeExclude:\n  - '@strapi/*'\n\nallowBuilds:\n  '@swc/core': true\n  better-sqlite3: true\n  core-js-pure: true\n  esbuild: true\n  sharp: true\n",
            $yaml,
        );
        self::assertStringNotContainsString('minimumReleaseAgeExclude', PnpmConfig::formatPnpmWorkspaceYaml(ScopeFactory::scope(['database' => ['client' => 'mysql', 'connection' => []]]), '10.26.0'));
    }
}
