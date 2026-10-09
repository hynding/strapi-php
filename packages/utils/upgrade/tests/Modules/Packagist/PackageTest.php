<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Packagist;

use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Packagist\Package;
use Strapi\Upgrade\Modules\Packagist\StrapiPackage;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** PHP-only: the Packagist version source and the Packagist ∩ npm strapi-php targets. */
final class PackageTest extends TestCase
{
    private string|false $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = getenv('PACKAGIST_URL');
        putenv('PACKAGIST_URL');
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? 'PACKAGIST_URL' : "PACKAGIST_URL={$this->previousEnv}");
        parent::tearDown();
    }

    /** a minified p2 document: only the first entry is complete */
    public static function p2(string ...$versions): string
    {
        $entries = [];
        foreach ($versions as $i => $version) {
            $entries[] = $i === 0
                ? ['name' => 'hynding/strapi-php', 'description' => 'Strapi', 'version' => "v{$version}", 'require' => ['php' => '>=8.3']]
                : ['version' => "v{$version}"];
        }

        return (string) json_encode(['minified' => 'composer/2.0', 'packages' => ['hynding/strapi-php' => $entries]]);
    }

    public function testExpandsTheMinifiedMetadata(): void
    {
        $expanded = Package::expand([
            ['name' => 'a/b', 'version' => '1.0.0', 'license' => ['MIT']],
            ['version' => '1.1.0', 'license' => '__unset'],
        ]);

        self::assertSame([['name' => 'a/b', 'version' => '1.0.0', 'license' => ['MIT']], ['name' => 'a/b', 'version' => '1.1.0']], $expanded);
    }

    public function testRefreshReadsTheP2DocumentFromPackagistUrl(): void
    {
        $mirror = $this->volume(['p2' => ['hynding' => ['strapi-php.json' => self::p2('5.56.0-beta.1', '5.56.0', '5.56.0.1', '5.57.0-beta.1')]]]);
        putenv("PACKAGIST_URL=file://{$mirror}");
        [$logger] = self::memoryLogger();

        $package = Package::packagistPackageFactory('hynding/strapi-php', '/nowhere', $logger)->refresh();

        self::assertSame(['5.56.0-beta.1', '5.56.0', '5.56.0.1', '5.57.0-beta.1'], array_keys($package->getVersionsDict()));
        self::assertSame('Strapi', $package->getVersionsDict()['5.57.0-beta.1']['description']);
        self::assertSame(['5.56.0', '5.56.0.1'], array_map(NpmPackage::versionOf(...), $package->findVersionsInRange(new Range('>5.56.0-beta.1'))));
        self::assertTrue($package->versionExists(new SemVer('5.57.0-beta.1')));
    }

    public function testUsesTheProjectComposerRepository(): void
    {
        $mirror = $this->volume(['p2' => ['hynding' => ['strapi-php.json' => self::p2('5.56.0')]]]);
        $cwd = $this->volume(['composer.json' => (string) json_encode(['repositories' => [['type' => 'path', 'url' => '../x'], ['type' => 'composer', 'url' => "file://{$mirror}/"]]])]);
        [$logger] = self::memoryLogger();

        self::assertTrue(Package::packagistPackageFactory('hynding/strapi-php', $cwd, $logger)->refresh()->isLoaded());
    }

    public function testUnreachableRepositoriesThrow(): void
    {
        [$logger] = self::memoryLogger();
        $package = new Package('hynding/strapi-php', '/nowhere', $logger, static fn (string $url): array => ['ok' => false, 'status' => 0, 'body' => '']);

        $this->expectExceptionMessage('Request failed for https://repo.packagist.org/p2/hynding/strapi-php.json');
        $package->refresh();
    }

    public function testStrapiPackageKeepsVersionsWhoseUpstreamReleaseIsOnNpm(): void
    {
        [$logger] = self::memoryLogger();
        $composer = new Package('hynding/strapi-php', '/nowhere', $logger, static fn (string $url): array => ['ok' => true, 'status' => 200, 'body' => self::p2('5.55.0', '5.56.0-beta.1', '5.56.0', '5.56.0.1', '5.57.0-beta.1', '5.58.0')]);
        $npm = new NpmPackage('@strapi/strapi', '/nowhere', $logger, [
            'fetch' => static fn (string $url): array => ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['versions' => ['5.56.0' => ['version' => '5.56.0'], '5.57.0' => ['version' => '5.57.0']]])],
            'getPreferred' => static fn (): ?string => null,
        ]);

        $package = (new StrapiPackage($composer, $npm))->refresh();

        self::assertSame('hynding/strapi-php', $package->name());
        self::assertSame(['5.56.0-beta.1', '5.56.0', '5.56.0.1', '5.57.0-beta.1'], array_keys($package->getVersionsDict()));
        self::assertSame('5.57.0', $package->getVersionsDict()['5.57.0-beta.1']['upstream']);
        self::assertSame(['5.56.0', '5.56.0.1'], array_map(NpmPackage::versionOf(...), $package->findVersionsInRange(new Range('>5.56.0-beta.1 <6.0.0'))));
        self::assertNull($package->findVersion(new SemVer('5.58.0')));
    }
}
