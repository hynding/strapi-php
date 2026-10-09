<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Packagist;

use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Npm\PackageInterface;
use Strapi\Upgrade\Modules\Upgrader\Constants as UpgraderConstants;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;

/**
 * PHP-only: the upgrade targets of a strapi-php project. A version is a target when
 * `hynding/strapi-php` has it on Packagist *and* the upstream release it mirrors (`5.57.0-beta.1` →
 * `5.57.0`, VERSIONING.md) is published as `@strapi/strapi` on npm — the admin bundle the
 * project's package.json will be pinned to has to exist. Each version entry is the Composer
 * manifest plus `upstream`.
 *
 * @phpstan-import-type NPMPackageVersion from \Strapi\Upgrade\Modules\Npm\Types
 */
final class StrapiPackage implements PackageInterface
{
    /** @var array<string, NPMPackageVersion>|null */
    private ?array $versions = null;

    public function __construct(private readonly PackageInterface $composerPackage, private readonly PackageInterface $npmPackage)
    {
    }

    public static function strapiPackageFactory(string $cwd, Logger $logger): self
    {
        return new self(
            Package::packagistPackageFactory(UpgraderConstants::STRAPI_COMPOSER_PACKAGE_NAME, $cwd, $logger),
            NpmPackage::npmPackageFactory(UpgraderConstants::STRAPI_PACKAGE_NAME, $cwd, $logger)
        );
    }

    public function name(): string
    {
        return $this->composerPackage->name();
    }

    public function isLoaded(): bool
    {
        return $this->versions !== null;
    }

    public function refresh(): static
    {
        $this->composerPackage->refresh();
        $this->npmPackage->refresh();

        $upstream = [];
        foreach ($this->npmPackage->getVersionsAsList() as $npmVersion) {
            $upstream[NpmPackage::versionOf($npmVersion)] = true;
        }

        $versions = [];
        foreach ($this->composerPackage->getVersionsDict() as $version => $manifest) {
            $base = UpgraderConstants::upstreamVersion((string) $version);
            if (isset($upstream[$base])) {
                $versions[(string) $version] = [...$manifest, 'upstream' => $base];
            }
        }

        $this->versions = $versions;

        return $this;
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
            if ($version->compare(NpmPackage::versionOf($candidate)) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    public function findVersionsInRange(SemverRange $range): array
    {
        $matches = array_values(array_filter(
            $this->getVersionsAsList(),
            static fn (array $v): bool => preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', NpmPackage::versionOf($v)) === 1 && $range->test(NpmPackage::versionOf($v))
        ));
        usort($matches, static fn (array $a, array $b): int => (new NodeSemVer(NpmPackage::versionOf($a)))->compare(NpmPackage::versionOf($b)));

        return $matches;
    }

    public function versionExists(NodeSemVer $version): bool
    {
        return $this->findVersion($version) !== null;
    }
}
