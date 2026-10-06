<?php

declare(strict_types=1);

namespace Strapi\Cli;

use Strapi\Core\Compile;
use Strapi\Core\Core;

/**
 * Port of packages/core/strapi/src/index.ts (`export * from '@strapi/core'`): the package's
 * public entry. `Strapi\Cli\Strapi::createStrapi()` is what a project's `public/index.php`
 * and the commands call; the version is the package's own (`composer.json`), the single
 * canonical version of the monorepo (see VERSIONING.md).
 */
final class Strapi
{
    private static ?string $version = null;

    /**
     * @param array{appDir?: string|null, distDir?: string|null, autoReload?: bool, serveAdminPanel?: bool} $options
     */
    public static function createStrapi(array $options = []): \Strapi\Core\Strapi
    {
        return Core::createStrapi($options);
    }

    /**
     * @param array{appDir?: string|null, distDir?: string|null} $options
     * @return array{appDir: string, distDir: string}
     */
    public static function compileStrapi(array $options = []): array
    {
        return Compile::compileStrapi($options);
    }

    /** The version of the `strapi/strapi` package (= the Strapi version it mirrors). */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $composer = dirname(__DIR__) . '/composer.json';
        $json = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;
        $version = is_array($json) && is_string($json['version'] ?? null) ? $json['version'] : null;

        if ($version === null && class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('strapi/strapi')) {
            $version = \Composer\InstalledVersions::getPrettyVersion('strapi/strapi');
        }

        return self::$version = $version ?? '0.0.0';
    }
}
