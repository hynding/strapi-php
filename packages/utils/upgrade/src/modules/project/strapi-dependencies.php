<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Semver;

/**
 * Port of packages/utils/upgrade/src/modules/project/strapi-dependencies.ts (`@strapi/*` in
 * package.json), plus the PHP-only Composer counterparts (`strapi/*` in composer.json's
 * `require` / `require-dev`).
 *
 * In composer.json a version is "pinned" when it is an exact version as VERSIONING.md writes
 * them: `5.56.0`, `5.56.0.1` or `5.56.0-beta.1` (Composer installs exactly that). In package.json
 * upstream's rule stands: `<major>.<minor>.<patch>` only.
 *
 * @phpstan-type StrapiDependencySection 'dependencies'|'devDependencies'|'require'|'require-dev'
 * @phpstan-type UnpinnedStrapiDependency array{name: string, declaredVersion: string, section: StrapiDependencySection}
 * @phpstan-import-type MinimalPackageJSON from Types
 */
final class StrapiDependencies
{
    /** @return array<string, string>|null */
    private static function asDependencyRecord(mixed $value): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        /** @var array<string, string> */
        return array_filter($value, 'is_string');
    }

    /**
     * @param MinimalPackageJSON $packageJSON
     * @return array{dependencies: array<string, string>|null, devDependencies: array<string, string>|null}
     */
    public static function getPackageDependencyRecords(array $packageJSON): array
    {
        return [
            'dependencies' => self::asDependencyRecord($packageJSON['dependencies'] ?? null),
            'devDependencies' => self::asDependencyRecord($packageJSON['devDependencies'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $composerJSON
     * @return array{require: array<string, string>|null, require-dev: array<string, string>|null}
     */
    public static function getComposerDependencyRecords(array $composerJSON): array
    {
        return [
            'require' => self::asDependencyRecord($composerJSON['require'] ?? null),
            'require-dev' => self::asDependencyRecord($composerJSON['require-dev'] ?? null),
        ];
    }

    public static function isPinnedSemVer(string $version): bool
    {
        return Semver::isLiteralSemVer($version) && self::valid($version) === $version;
    }

    /** PHP-only: an exact strapi-php version (`5.56.0`, `5.56.0.1`, `5.56.0-beta.1`) */
    public static function isPinnedComposerVersion(string $version): bool
    {
        return self::valid($version) === $version;
    }

    private static function valid(string $version): ?string
    {
        try {
            return (new NodeSemVer($version))->version;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, string>|null $dependencies
     * @param array<string, string>|null $devDependencies
     * @return list<UnpinnedStrapiDependency>
     */
    public static function findUnpinnedStrapiDependencies(?array $dependencies, ?array $devDependencies): array
    {
        return self::findUnpinned(
            ['dependencies' => $dependencies, 'devDependencies' => $devDependencies],
            Constants::isScopedStrapiPackage(...),
            self::isPinnedSemVer(...)
        );
    }

    /**
     * PHP-only: strapi-php Composer requirements (`hynding/strapi-php`, `strapi/*`) that aren't exact versions.
     *
     * @param array<string, string>|null $require
     * @param array<string, string>|null $requireDev
     * @return list<UnpinnedStrapiDependency>
     */
    public static function findUnpinnedComposerStrapiDependencies(?array $require, ?array $requireDev): array
    {
        return self::findUnpinned(
            ['require' => $require, 'require-dev' => $requireDev],
            Constants::isStrapiComposerPackage(...),
            self::isPinnedComposerVersion(...)
        );
    }

    /**
     * @param array<StrapiDependencySection, array<string, string>|null> $sections
     * @param \Closure(string): bool $isStrapiPackage
     * @param \Closure(string): bool $isPinned
     * @return list<UnpinnedStrapiDependency>
     */
    private static function findUnpinned(array $sections, \Closure $isStrapiPackage, \Closure $isPinned): array
    {
        $unpinned = [];

        foreach ($sections as $section => $deps) {
            if ($deps === null) {
                continue;
            }

            foreach ($deps as $name => $version) {
                $name = (string) $name;
                if ($isStrapiPackage($name) && !$isPinned($version)) {
                    $unpinned[] = ['name' => $name, 'declaredVersion' => $version, 'section' => $section];
                }
            }
        }

        return $unpinned;
    }

    /**
     * The version to pin to: the declared `hynding/strapi-php` version when exact, else the floor of
     * its constraint, else the installed version.
     */
    public static function getStrapiPinTargetVersion(AppProject $project): NodeSemVer
    {
        $require = is_array($project->composerJSON['require'] ?? null) ? $project->composerJSON['require'] : [];
        $declared = $require[Constants::STRAPI_COMPOSER_DEPENDENCY_NAME] ?? null;

        if (!is_string($declared) || $declared === '') {
            $name = (string) ($project->composerJSON['name'] ?? $project->packageJSON['name'] ?? '');

            throw new \RuntimeException('No version of ' . Constants::STRAPI_COMPOSER_DEPENDENCY_NAME . " was found in {$name}");
        }

        if (self::isPinnedComposerVersion($declared)) {
            return Semver::semVerFactory($declared);
        }

        try {
            $minVersion = SemverRange::minVersion(self::composerConstraintToRange($declared));
        } catch (\InvalidArgumentException) {
            $minVersion = null;
        }

        if ($minVersion !== null) {
            return Semver::semVerFactory($minVersion->version);
        }

        return $project->getInstalledStrapiVersion();
    }

    /** a Composer constraint in `semver` range syntax: stability flags dropped, `,` (AND) as a space */
    public static function composerConstraintToRange(string $constraint): string
    {
        $constraint = (string) preg_replace('/@[a-zA-Z]+/', '', $constraint);

        return trim((string) preg_replace('/\s*,\s*/', ' ', $constraint));
    }

    /**
     * @param MinimalPackageJSON $packageJSON
     * @param list<UnpinnedStrapiDependency> $unpinned
     * @return MinimalPackageJSON
     */
    public static function pinStrapiDependencies(array $packageJSON, string $pinVersion, array $unpinned): array
    {
        return self::pin($packageJSON, $pinVersion, $unpinned, 'dependencies', 'devDependencies');
    }

    /**
     * PHP-only: the composer.json counterpart of `pinStrapiDependencies`.
     *
     * @param array<string, mixed> $composerJSON
     * @param list<UnpinnedStrapiDependency> $unpinned
     * @return array<string, mixed>
     */
    public static function pinComposerStrapiDependencies(array $composerJSON, string $pinVersion, array $unpinned): array
    {
        return self::pin($composerJSON, $pinVersion, $unpinned, 'require', 'require-dev');
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<UnpinnedStrapiDependency> $unpinned
     * @return array<string, mixed>
     */
    private static function pin(array $manifest, string $pinVersion, array $unpinned, string $main, string $dev): array
    {
        $dependencies = self::asDependencyRecord($manifest[$main] ?? null) ?? [];
        $devDependencies = self::asDependencyRecord($manifest[$dev] ?? null) ?? [];

        foreach ($unpinned as ['name' => $name, 'section' => $section]) {
            if ($section === $main) {
                $dependencies[$name] = $pinVersion;
            } else {
                $devDependencies[$name] = $pinVersion;
            }
        }

        // `{ ...packageJSON, dependencies, devDependencies }`; an empty record stays a JSON object
        return [
            ...$manifest,
            $main => $dependencies === [] ? new \stdClass() : $dependencies,
            $dev => $devDependencies === [] ? new \stdClass() : $devDependencies,
        ];
    }
}
