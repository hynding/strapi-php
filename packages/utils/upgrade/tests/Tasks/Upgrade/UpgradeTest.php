<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Tasks\Upgrade;

use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Packagist\Package as PackagistPackage;
use Strapi\Upgrade\Modules\Packagist\StrapiPackage;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Modules\Version\Types;
use Strapi\Upgrade\Tasks\Upgrade\Upgrade;
use Strapi\Upgrade\Tests\Modules\Packagist\PackageTest as PackagistPackageTest;
use Strapi\Upgrade\Tests\TestCase;

/** PHP-only: the upgrade task end to end (dry), with fake Packagist and npm sources. */
final class UpgradeTest extends TestCase
{
    private function source(string ...$phpVersions): StrapiPackage
    {
        [$logger] = self::memoryLogger();
        $composer = new PackagistPackage('hynding/strapi-php', '/x', $logger, static fn (): array => ['ok' => true, 'status' => 200, 'body' => PackagistPackageTest::p2(...$phpVersions)]);
        $npm = new NpmPackage('@strapi/strapi', '/x', $logger, [
            'fetch' => static fn (): array => ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['versions' => ['5.56.0' => ['version' => '5.56.0'], '5.57.0' => ['version' => '5.57.0'], '6.0.0' => ['version' => '6.0.0']]])],
            'getPreferred' => static fn (): ?string => null,
        ]);

        return new StrapiPackage($composer, $npm);
    }

    public function testDryUpgradeRunsEveryStepAndWritesNothing(): void
    {
        $cwd = $this->volume(self::appTree('5.56.0-beta.1'));
        $before = [file_get_contents("{$cwd}/composer.json"), file_get_contents("{$cwd}/package.json")];
        [$logger, $out, $err] = self::memoryLogger(debug: true);

        Upgrade::upgrade(['logger' => $logger, 'cwd' => $cwd, 'dry' => true, 'target' => new SemVer('5.56.0'), 'confirm' => static fn (): bool => true, 'npmPackage' => $this->source('5.56.0-beta.1', '5.56.0')]);

        $log = self::read($out) . self::read($err);
        self::assertStringContainsString('Upgrading from v5.56.0-beta.1 to v5.56.0', $log);
        self::assertStringContainsString('(4/4) Installing dependencies...', $log);
        self::assertStringContainsString('- hynding/strapi-php (5.56.0-beta.1 -> 5.56.0)', $log);
        self::assertMatchesRegularExpression('/Completed in \d+\.\d{3}s/', $log);
        self::assertSame($before, [file_get_contents("{$cwd}/composer.json"), file_get_contents("{$cwd}/package.json")]);
    }

    public function testLatestWarnsAndAsksBeforeAMajorUpgrade(): void
    {
        $cwd = $this->volume(self::appTree('5.56.0'));
        [$logger, , $err] = self::memoryLogger();
        $asked = [];

        Upgrade::upgrade(['logger' => $logger, 'cwd' => $cwd, 'dry' => true, 'target' => Types::LATEST, 'confirm' => static function (string $message) use (&$asked): bool {
            $asked[] = $message;

            return true;
        }, 'npmPackage' => $this->source('5.56.0', '5.57.0', '6.0.0')]);

        self::assertStringContainsString('Detected a major upgrade for the "latest" tag: v5.56.0 > v6.0.0', self::read($err));
        self::assertStringContainsString('upgrade to the latest version of v5 (v5.57.0)', self::read($err));
        self::assertContains("I know what I'm doing. Proceed anyway!", $asked);
    }

    public function testMajorRequiresTheLatestOfTheCurrentMajor(): void
    {
        $cwd = $this->volume(self::appTree('5.56.0'));
        [$logger] = self::memoryLogger();

        $this->expectExceptionMessage('Doing a major upgrade requires to be on the latest v5 version, but found 1 versions between the current one and 6.0.0. Please upgrade to 5.57.0 and try again.');
        Upgrade::upgrade(['logger' => $logger, 'cwd' => $cwd, 'dry' => true, 'target' => Types::MAJOR, 'confirm' => static fn (): bool => true, 'npmPackage' => $this->source('5.56.0', '5.57.0', '6.0.0')]);
    }

    public function testPluginsMustUseCodemods(): void
    {
        [$logger] = self::memoryLogger();

        $this->expectExceptionMessage('The "minor" upgrade can only be run on a Strapi project; for plugins, please use "codemods".');
        Upgrade::upgrade(['logger' => $logger, 'cwd' => $this->volume(['package.json' => '{"strapi": {"kind": "plugin"}}']), 'target' => Types::MINOR]);
    }
}
