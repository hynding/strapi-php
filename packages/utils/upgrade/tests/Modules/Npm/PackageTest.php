<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Npm;

use Strapi\Upgrade\Modules\Npm\Constants;
use Strapi\Upgrade\Modules\Npm\Package;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/npm/__tests__/package.test.ts (injected fetch / exec / getPreferred instead of Jest mocks). */
final class PackageTest extends TestCase
{
    private const CWD = '/path/to/project';

    /** @var list<string> */
    private array $fetched = [];

    /** @var list<array{string, list<string>, float}> */
    private array $executed = [];

    /** @var list<string> */
    private array $execOutputs = [];

    private ?string $preferred = null;

    private string|false $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = getenv('NPM_REGISTRY_URL');
        putenv('NPM_REGISTRY_URL');
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? 'NPM_REGISTRY_URL' : "NPM_REGISTRY_URL={$this->previousEnv}");
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> */
    private static function versions(): array
    {
        $versions = [];
        foreach (['1.0.0' => 'Test a new feature', '2.0.0' => 'Test a new feature v2', '3.0.0' => 'Test a new feature v3'] as $version => $description) {
            $versions[$version] = ['name' => '@test/test', 'description' => $description, 'version' => $version, 'repository' => ['type' => 'git', 'url' => 'git+ssh://git@github.com/test/test.js.git']];
        }

        return $versions;
    }

    private function package(string $name = '@test/test'): Package
    {
        [$logger] = self::memoryLogger();

        return new Package($name, self::CWD, $logger, [
            'fetch' => function (string $url): array {
                $this->fetched[] = $url;

                return ['ok' => true, 'status' => 200, 'body' => (string) json_encode(['_id' => '@test/test', 'name' => '@test/test', 'versions' => self::versions()])];
            },
            'exec' => function (string $command, array $args, float $timeout): string {
                $this->executed[] = [$command, $args, $timeout];

                return array_shift($this->execOutputs) ?? '';
            },
            'getPreferred' => fn (string $cwd): ?string => $this->preferred,
        ]);
    }

    public function testNpmPackageFactoryCreatesANewPackageInstance(): void
    {
        [$logger] = self::memoryLogger();
        $package = Package::npmPackageFactory('example-package', self::CWD, $logger);

        self::assertSame('example-package', $package->name);
        self::assertSame(self::CWD, $package->cwd);
        self::assertFalse($package->isLoaded());
    }

    public function testRefreshFetchesThePackageData(): void
    {
        $package = $this->package();
        self::assertFalse($package->isLoaded());

        $package->refresh();

        self::assertSame([Constants::NPM_REGISTRY_URL . '/@test/test'], $this->fetched);
        self::assertTrue($package->isLoaded());
        self::assertSame(self::versions(), $package->getVersionsDict());
        self::assertSame(array_values(self::versions()), $package->getVersionsAsList());
    }

    public function testFindVersionsInRange(): void
    {
        $package = $this->package()->refresh();

        self::assertSame(array_slice(array_values(self::versions()), 0, 2), $package->findVersionsInRange(new Range('>=1.0.0 <3.0.0')));
    }

    public function testFindVersionAndVersionExists(): void
    {
        $package = $this->package()->refresh();

        self::assertTrue($package->versionExists(new SemVer('1.0.0')));
        self::assertFalse($package->versionExists(new SemVer('1.1.1')));
        self::assertNotNull($package->findVersion(new SemVer('1.0.0')));
        self::assertNull($package->findVersion(new SemVer('1.1.1')));
    }

    public function testUsesTheNpmRegistryUrlEnvironmentVariable(): void
    {
        putenv('NPM_REGISTRY_URL=https://custom-registry.example.com/');
        $this->preferred = 'npm';

        $this->package('@test/package')->refresh();

        self::assertSame(['https://custom-registry.example.com/@test/package'], $this->fetched);
        self::assertSame([], $this->executed);
    }

    public function testUsesYarnBerryNpmRegistryServer(): void
    {
        $this->preferred = 'yarn';
        $this->execOutputs = ['4.0.0', 'https://yarn-registry.example.com/'];

        $this->package('@test/package')->refresh();

        self::assertSame([['yarn', ['--version'], 5.0], ['yarn', ['config', 'get', 'npmRegistryServer'], 10.0]], $this->executed);
        self::assertSame(['https://yarn-registry.example.com/@test/package'], $this->fetched);
    }

    public function testUsesYarnClassicRegistry(): void
    {
        $this->preferred = 'yarn';
        $this->execOutputs = ['1.22.22', 'https://yarn-v1-registry.example.com/'];

        $this->package('@test/package')->refresh();

        self::assertSame(['yarn', ['config', 'get', 'registry'], 10.0], $this->executed[1]);
        self::assertSame(['https://yarn-v1-registry.example.com/@test/package'], $this->fetched);
    }

    public function testFallsBackWhenThePackageManagerReturnsUndefined(): void
    {
        $this->preferred = 'yarn';
        $this->execOutputs = ['1.22.22', 'undefined'];

        $this->package('@test/package')->refresh();

        self::assertSame([Constants::NPM_REGISTRY_URL . '/@test/package'], $this->fetched);
    }

    public function testUsesNpmAndPnpmRegistries(): void
    {
        foreach (['npm', 'pnpm'] as $pm) {
            $this->preferred = $pm;
            $this->execOutputs = ["https://{$pm}-registry.example.com/"];
            $this->executed = $this->fetched = [];

            $this->package('@test/package')->refresh();

            self::assertSame([[$pm, ['config', 'get', 'registry'], 10.0]], $this->executed);
            self::assertSame(["https://{$pm}-registry.example.com/@test/package"], $this->fetched);
        }
    }

    public function testFallsBackToTheDefaultRegistry(): void
    {
        $this->package('@test/package')->refresh();

        self::assertSame([Constants::NPM_REGISTRY_URL . '/@test/package'], $this->fetched);
    }

    public function testFailedRequestsThrow(): void
    {
        [$logger] = self::memoryLogger();
        $package = new Package('@test/package', self::CWD, $logger, ['fetch' => static fn (string $url): array => ['ok' => false, 'status' => 404, 'body' => ''], 'getPreferred' => static fn (): ?string => null]);

        $this->expectExceptionMessage('Request failed for ' . Constants::NPM_REGISTRY_URL . '/@test/package');
        $package->refresh();
    }
}
