<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Npm;

use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Semver;
use Strapi\Utils\PackageManager;
use Symfony\Component\Process\Process;

/**
 * Port of packages/utils/upgrade/src/modules/npm/package.ts: a package's document on the npm
 * registry. The registry is `NPM_REGISTRY_URL`, else the project's package manager
 * configuration (`npm|pnpm config get registry`, `yarn config get registry|npmRegistryServer`),
 * else registry.npmjs.org.
 *
 * PHP-only: HTTP, process execution and package-manager detection are injectable (upstream's
 * tests mock `fetch`, `execa` and `@strapi/utils`). An `Exec` returns the stdout of
 * `<command> <args>` (timeout in seconds) and throws on failure.
 *
 * @phpstan-import-type NPMPackage from Types
 * @phpstan-import-type NPMPackageVersion from Types
 * @phpstan-import-type Fetcher from Types
 * @phpstan-type Exec \Closure(string, list<string>, float): string
 * @phpstan-type Dependencies array{fetch?: Fetcher, exec?: Exec, getPreferred?: \Closure(string): ?string}
 */
class Package implements PackageInterface
{
    /** @var NPMPackage|null */
    private ?array $npmPackage = null;

    /** @var Fetcher */
    private \Closure $fetch;

    /** @var Exec */
    private \Closure $exec;

    /** @var \Closure(string): ?string */
    private \Closure $getPreferred;

    /** @param Dependencies $dependencies */
    public function __construct(public readonly string $name, public readonly string $cwd, private readonly Logger $logger, array $dependencies = [])
    {
        $this->fetch = $dependencies['fetch'] ?? (new Fetch())(...);
        $this->exec = $dependencies['exec'] ?? static function (string $command, array $args, float $timeout): string {
            $process = new Process([$command, ...$args], null, null, null, $timeout);
            $process->mustRun();

            return $process->getOutput();
        };
        $this->getPreferred = $dependencies['getPreferred'] ?? static fn (string $cwd): string => PackageManager::getPreferred($cwd);
    }

    /** @param Dependencies $dependencies */
    public static function npmPackageFactory(string $name, string $cwd, Logger $logger, array $dependencies = []): self
    {
        return new self($name, $cwd, $logger, $dependencies);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isLoaded(): bool
    {
        return $this->npmPackage !== null;
    }

    /** @return NPMPackage */
    private function assertPackageIsLoaded(): array
    {
        if ($this->npmPackage === null) {
            throw new \LogicException('The package is not loaded yet');
        }

        return $this->npmPackage;
    }

    public function getVersionsDict(): array
    {
        return $this->assertPackageIsLoaded()['versions'];
    }

    public function getVersionsAsList(): array
    {
        return array_values($this->assertPackageIsLoaded()['versions']);
    }

    public function findVersionsInRange(SemverRange $range): array
    {
        return self::filterAndSort(
            $this->getVersionsAsList(),
            // Only select versions matching the upgrade range, in the supported format (x.x.x)
            static fn (string $version): bool => $range->test($version) && Semver::isLiteralSemVer($version)
        );
    }

    /**
     * @param list<NPMPackageVersion> $versions
     * @param \Closure(string): bool $filter
     * @return list<NPMPackageVersion>
     */
    protected static function filterAndSort(array $versions, \Closure $filter): array
    {
        $matches = array_values(array_filter($versions, static fn (array $v): bool => $filter(self::versionOf($v))));

        // Sort in ascending order
        usort($matches, static fn (array $a, array $b): int => (new NodeSemVer(self::versionOf($a)))->compare(self::versionOf($b)));

        return $matches;
    }

    /** @param NPMPackageVersion $version */
    public static function versionOf(array $version): string
    {
        return is_string($version['version'] ?? null) ? $version['version'] : '';
    }

    private function getYarnMajorVersion(): int
    {
        try {
            $version = trim(($this->exec)('yarn', ['--version'], 5.0));

            return (int) explode('.', $version)[0];
        } catch (\Throwable) {
            // Default to Yarn Berry (v2+) if version check fails
            return 2;
        }
    }

    private function normalizeRegistryOutput(string $stdout): ?string
    {
        $registry = trim($stdout);

        // Yarn Classic (v1) may return literal "undefined" for unset config values
        return $registry === '' || $registry === 'undefined' ? null : $registry;
    }

    private function getRegistryFromPackageManager(): ?string
    {
        try {
            $packageManagerName = ($this->getPreferred)($this->cwd);
            if ($packageManagerName === null || $packageManagerName === '') {
                return null;
            }

            if ($packageManagerName === 'yarn') {
                // Yarn Classic (v1) uses 'registry', Yarn Berry (v2+) uses 'npmRegistryServer'
                $command = $this->getYarnMajorVersion() >= 2 ? ['config', 'get', 'npmRegistryServer'] : ['config', 'get', 'registry'];
            } elseif ($packageManagerName === 'npm' || $packageManagerName === 'pnpm') {
                $command = ['config', 'get', 'registry'];
            } else {
                $this->logger->warn("Unsupported package manager: {$packageManagerName}");

                return null;
            }

            return $this->normalizeRegistryOutput(($this->exec)($packageManagerName, $command, 10.0));
        } catch (\Throwable) {
            $this->logger->warn('Failed to determine registry URL from package manager');

            return null;
        }
    }

    private function determineRegistryUrl(): string
    {
        $env = getenv('NPM_REGISTRY_URL');
        if (is_string($env) && $env !== '') {
            $this->logger->debug("Using NPM_REGISTRY_URL: {$env}");

            return rtrim($env, '/');
        }

        $packageManagerRegistry = $this->getRegistryFromPackageManager();
        if ($packageManagerRegistry !== null) {
            $this->logger->debug("Using package manager registry: {$packageManagerRegistry}");

            return rtrim($packageManagerRegistry, '/');
        }

        $this->logger->debug('Using default registry: ' . Constants::NPM_REGISTRY_URL);

        return rtrim(Constants::NPM_REGISTRY_URL, '/');
    }

    public function findVersion(NodeSemVer $version): ?array
    {
        foreach ($this->getVersionsAsList() as $npmVersion) {
            try {
                if ($version->compare(self::versionOf($npmVersion)) === 0) {
                    return $npmVersion;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    public function refresh(): static
    {
        $packageURL = $this->determineRegistryUrl() . '/' . $this->name;

        $response = ($this->fetch)($packageURL);

        if (!$response['ok']) {
            throw new \RuntimeException("Request failed for {$packageURL}");
        }

        $document = json_decode($response['body'], true);
        if (!is_array($document) || !is_array($document['versions'] ?? null)) {
            throw new \RuntimeException("Invalid package document received from {$packageURL}");
        }

        /** @var NPMPackage $document */
        $this->npmPackage = $document;

        return $this;
    }

    public function versionExists(NodeSemVer $version): bool
    {
        return $this->findVersion($version) !== null;
    }
}
