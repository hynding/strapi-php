<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Types;

/**
 * Port of packages/cli/create-strapi-app/src/utils/pnpm-config.ts. pnpm installs the admin
 * bundle; its build-script allow list keeps upstream's packages. `better-sqlite3` is only added
 * for SQLite like upstream, although the PHP backend talks to SQLite through pdo_sqlite: an
 * admin-only install never builds it, the entry is inert.
 *
 * @phpstan-import-type Scope from Types
 */
final class PnpmConfig
{
    /** allowBuilds in pnpm-workspace.yaml (pnpm >= 10.26). */
    public const PNPM_WORKSPACE_CONFIG_MIN_VERSION = '10.26.0';

    /** pnpm 11 reads project settings from pnpm-workspace.yaml only. */
    public const PNPM11_MIN_VERSION = '11.0.0';

    /** Native / tooling packages Strapi needs to run postinstall scripts for (admin build, upload, sqlite). */
    public const PNPM_STRAPI_BUILD_PACKAGES = ['@swc/core', 'core-js-pure', 'esbuild', 'sharp'];

    public const PNPM_SQLITE_BUILD_PACKAGE = 'better-sqlite3';

    public static function shouldUsePnpmWorkspaceConfig(?string $pnpmVersion): bool
    {
        $normalized = $pnpmVersion !== null && $pnpmVersion !== '' ? Semver::coerce($pnpmVersion) : null;

        return $normalized !== null ? Semver::gte($normalized, self::PNPM_WORKSPACE_CONFIG_MIN_VERSION) : true;
    }

    public static function shouldUsePackageJsonPnpmConfig(?string $pnpmVersion): bool
    {
        $normalized = $pnpmVersion !== null && $pnpmVersion !== '' ? Semver::coerce($pnpmVersion) : null;

        if ($normalized === null) {
            return false;
        }

        return Semver::lt($normalized, self::PNPM_WORKSPACE_CONFIG_MIN_VERSION);
    }

    /**
     * @param Scope $scope
     * @return list<string>
     */
    public static function getPnpmBuildPackageNames(array $scope): array
    {
        $packages = self::PNPM_STRAPI_BUILD_PACKAGES;

        if ($scope['database']['client'] === 'sqlite') {
            $packages[] = self::PNPM_SQLITE_BUILD_PACKAGE;
        }

        $packages = array_values(array_unique($packages));
        sort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * @param Scope $scope
     * @return array<string, true>
     */
    public static function getPnpmAllowBuilds(array $scope): array
    {
        return array_fill_keys(self::getPnpmBuildPackageNames($scope), true);
    }

    /**
     * @param Scope $scope
     * @param list<string> $existing
     * @return list<string>
     */
    public static function getPnpmOnlyBuiltDependencies(array $scope, array $existing = []): array
    {
        $all = array_values(array_unique([...$existing, ...self::getPnpmBuildPackageNames($scope)]));
        sort($all, SORT_STRING);

        return $all;
    }

    private static function quoteYamlKey(string $key): string
    {
        if (preg_match('/^[a-z0-9_-]+$/', $key) === 1) {
            return $key;
        }

        return "'{$key}'";
    }

    /** @param Scope $scope */
    public static function formatPnpmWorkspaceYaml(array $scope, ?string $pnpmVersion): string
    {
        $allowBuilds = self::getPnpmAllowBuilds($scope);
        $normalized = $pnpmVersion !== null && $pnpmVersion !== '' ? Semver::coerce($pnpmVersion) : null;
        $isPnpm11 = $normalized !== null ? Semver::gte($normalized, self::PNPM11_MIN_VERSION) : true;

        $lines = ['packages:', "  - '.'", ''];

        if ($isPnpm11) {
            array_push(
                $lines,
                '# Allow day-0 @strapi/* npm publishes while keeping pnpm 11 supply-chain defaults for other deps.',
                'minimumReleaseAgeExclude:',
                "  - '@strapi/*'",
                '',
            );
        }

        $lines[] = 'allowBuilds:';
        $names = array_keys($allowBuilds);
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            $lines[] = '  ' . self::quoteYamlKey($name) . ': true';
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function hasParentPnpmWorkspace(string $rootPath): bool
    {
        $dir = dirname($rootPath);

        while ($dir !== dirname($dir)) {
            if (file_exists($dir . '/pnpm-workspace.yaml')) {
                return true;
            }

            $dir = dirname($dir);
        }

        return false;
    }

    /** @param Scope $scope */
    public static function writePnpmWorkspaceConfig(array $scope, ?string $pnpmVersion): void
    {
        if ($scope['packageManager'] !== 'pnpm' || !self::shouldUsePnpmWorkspaceConfig($pnpmVersion)) {
            return;
        }

        $workspaceFile = $scope['rootPath'] . '/pnpm-workspace.yaml';

        if (self::hasParentPnpmWorkspace($scope['rootPath'])) {
            return;
        }

        if (file_exists($workspaceFile)) {
            return;
        }

        file_put_contents($workspaceFile, self::formatPnpmWorkspaceYaml($scope, $pnpmVersion));
    }
}
