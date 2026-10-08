<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Npm;

use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;

/**
 * The `Package` interface of packages/utils/upgrade/src/modules/npm/types.ts: a package's
 * published versions. Implemented by the npm registry `Package` and, for strapi-php projects,
 * by `Packagist\StrapiPackage`.
 *
 * @phpstan-import-type NPMPackageVersion from Types
 */
interface PackageInterface
{
    public function name(): string;

    public function isLoaded(): bool;

    public function refresh(): static;

    public function versionExists(NodeSemVer $version): bool;

    /** @return array<string, NPMPackageVersion> */
    public function getVersionsDict(): array;

    /** @return list<NPMPackageVersion> */
    public function getVersionsAsList(): array;

    /** @return NPMPackageVersion|null */
    public function findVersion(NodeSemVer $version): ?array;

    /** @return list<NPMPackageVersion> ascending */
    public function findVersionsInRange(SemverRange $range): array;
}
