<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Upgrader;

/**
 * Port of packages/utils/upgrade/src/modules/upgrader/constants.ts.
 *
 * PHP-only: the Composer package that carries a strapi-php project's version, and this tool's
 * own version (which, per VERSIONING.md, is the Strapi version it mirrors).
 */
final class Constants
{
    public const STRAPI_PACKAGE_NAME = '@strapi/strapi';

    public const STRAPI_COMPOSER_PACKAGE_NAME = 'strapi/strapi';

    public const UPGRADE_NPM_PACKAGE_NAME = '@strapi/upgrade';

    private static ?string $version = null;

    /** this package's version, e.g. `5.56.0-beta.1` */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $composer = dirname(__DIR__, 3) . '/composer.json';
        $json = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;
        $version = is_array($json) && is_string($json['version'] ?? null) ? $json['version'] : null;

        if ($version === null && class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('strapi/upgrade')) {
            $version = \Composer\InstalledVersions::getPrettyVersion('strapi/upgrade');
        }

        return self::$version = ltrim($version ?? '0.0.0', 'v');
    }

    /** the upstream release a strapi-php version mirrors: `5.56.0-beta.1` and `5.56.0.1` → `5.56.0` (VERSIONING.md rule 2) */
    public static function upstreamVersion(?string $version = null): string
    {
        $version ??= self::version();

        return preg_match('/^v?(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }
}
