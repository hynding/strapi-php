<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Packagist;

use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Npm\Fetch;
use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Npm\PackageInterface;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Semver;

/**
 * PHP-only: the npm `Package`'s counterpart for a Composer package — its tagged versions from a
 * Composer v2 repository's metadata (`<repository>/p2/<name>.json`, minified format, expanded).
 *
 * The repository is `PACKAGIST_URL`, else the first `composer` repository declared in the
 * project's composer.json, else https://repo.packagist.org. `file://` URLs work (a local mirror,
 * or a fake one in tests).
 *
 * @phpstan-import-type NPMPackageVersion from \Strapi\Upgrade\Modules\Npm\Types
 * @phpstan-import-type Fetcher from \Strapi\Upgrade\Modules\Npm\Types
 */
class Package implements PackageInterface
{
    public const PACKAGIST_URL = 'https://repo.packagist.org';

    /** @var array<string, NPMPackageVersion>|null versions keyed by version, `v` prefix dropped */
    private ?array $versions = null;

    /** @var Fetcher */
    private \Closure $fetch;

    /** @param Fetcher|null $fetch */
    public function __construct(public readonly string $name, public readonly string $cwd, private readonly Logger $logger, ?\Closure $fetch = null)
    {
        $this->fetch = $fetch ?? (new Fetch())(...);
    }

    /** @param Fetcher|null $fetch */
    public static function packagistPackageFactory(string $name, string $cwd, Logger $logger, ?\Closure $fetch = null): self
    {
        return new self($name, $cwd, $logger, $fetch);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isLoaded(): bool
    {
        return $this->versions !== null;
    }

    public function getVersionsDict(): array
    {
        if ($this->versions === null) {
            throw new \LogicException('The package is not loaded yet');
        }

        return $this->versions;
    }

    public function getVersionsAsList(): array
    {
        return array_values($this->getVersionsDict());
    }

    public function findVersion(NodeSemVer $version): ?array
    {
        foreach ($this->getVersionsAsList() as $candidate) {
            try {
                if ($version->compare(NpmPackage::versionOf($candidate)) === 0) {
                    return $candidate;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    public function findVersionsInRange(SemverRange $range): array
    {
        // like npm's: only stable versions (x.y.z, and VERSIONING.md's x.y.z.N), ascending
        $matches = array_values(array_filter(
            $this->getVersionsAsList(),
            static fn (array $v): bool => Semver::isLiteralVersion(NpmPackage::versionOf($v)) && $range->test(NpmPackage::versionOf($v))
        ));
        usort($matches, static fn (array $a, array $b): int => (new NodeSemVer(NpmPackage::versionOf($a)))->compare(NpmPackage::versionOf($b)));

        return $matches;
    }

    public function versionExists(NodeSemVer $version): bool
    {
        return $this->findVersion($version) !== null;
    }

    public function refresh(): static
    {
        $url = $this->determineRepositoryUrl() . "/p2/{$this->name}.json";

        $response = ($this->fetch)($url);
        if (!$response['ok']) {
            throw new \RuntimeException("Request failed for {$url}");
        }

        $document = json_decode($response['body'], true);
        $entries = is_array($document) ? ($document['packages'][$this->name] ?? null) : null;
        if (!is_array($entries)) {
            throw new \RuntimeException("Invalid package metadata received from {$url}");
        }

        $versions = [];
        foreach (self::expand(array_values(array_filter($entries, 'is_array'))) as $manifest) {
            $version = is_string($manifest['version'] ?? null) ? ltrim($manifest['version'], 'v') : null;
            // tagged versions only (no dev branches); keys must be valid versions to be targets
            if ($version !== null && Semver::isValidSemVer($version)) {
                $versions[$version] = ['version' => $version] + $manifest;
            }
        }

        $this->versions = $versions;

        return $this;
    }

    private function determineRepositoryUrl(): string
    {
        $env = getenv('PACKAGIST_URL');
        if (is_string($env) && $env !== '') {
            $this->logger->debug("Using PACKAGIST_URL: {$env}");

            return rtrim($env, '/');
        }

        $composerJSONPath = $this->cwd . '/composer.json';
        $composerJSON = is_file($composerJSONPath) ? json_decode((string) file_get_contents($composerJSONPath), true) : null;
        $repositories = is_array($composerJSON) && is_array($composerJSON['repositories'] ?? null) ? $composerJSON['repositories'] : [];
        foreach ($repositories as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'composer' && is_string($repository['url'] ?? null)) {
                $this->logger->debug("Using composer.json repository: {$repository['url']}");

                return rtrim($repository['url'], '/');
            }
        }

        $this->logger->debug('Using default repository: ' . self::PACKAGIST_URL);

        return self::PACKAGIST_URL;
    }

    /**
     * Composer's `MetadataMinifier::expand()`: each entry only lists what changed since the
     * previous one; `"__unset"` removes a key.
     *
     * @param list<array<string, mixed>> $versions
     * @return list<array<string, mixed>>
     */
    public static function expand(array $versions): array
    {
        $expanded = [];
        $previous = null;
        foreach ($versions as $version) {
            if ($previous === null) {
                $expanded[] = $previous = $version;
                continue;
            }

            foreach ($version as $key => $value) {
                if ($value === '__unset') {
                    unset($previous[$key]);
                } else {
                    $previous[$key] = $value;
                }
            }
            $expanded[] = $previous;
        }

        return $expanded;
    }
}
