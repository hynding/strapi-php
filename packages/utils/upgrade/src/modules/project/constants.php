<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

/**
 * Port of packages/utils/upgrade/src/modules/project/constants.ts.
 *
 * PHP-only additions: `composer.json` (the manifest a strapi-php project's version lives in),
 * PHP sources, the strapi-php Composer packages, and `vendor/` excluded like `node_modules/`.
 *
 * strapi-php is published as one Composer package, `hynding/strapi-php`, which `replace`s every
 * `strapi/*` package; a project requires it and its version is the project's Strapi version.
 */
final class Constants
{
    public const PROJECT_PACKAGE_JSON = 'package.json';

    public const PROJECT_COMPOSER_JSON = 'composer.json';

    public const PROJECT_APP_ALLOWED_ROOT_PATHS = ['src', 'config', 'public'];

    public const PROJECT_PLUGIN_ALLOWED_ROOT_PATHS = ['admin', 'server'];

    public const PROJECT_PLUGIN_ROOT_FILES = ['strapi-admin.js', 'strapi-server.js'];

    public const PROJECT_CODE_EXTENSIONS = [
        // Source files
        'js',
        'mjs',
        'ts',
        // React files
        'jsx',
        'tsx',
    ];

    public const PROJECT_PHP_EXTENSIONS = ['php'];

    public const PROJECT_JSON_EXTENSIONS = ['json'];

    public const PROJECT_ALLOWED_EXTENSIONS = [...self::PROJECT_CODE_EXTENSIONS, ...self::PROJECT_PHP_EXTENSIONS, ...self::PROJECT_JSON_EXTENSIONS];

    public const PROJECT_EXCLUDED_DIRECTORIES = ['node_modules', 'dist', 'vendor'];

    public const SCOPED_STRAPI_PACKAGE_PREFIX = '@strapi/';

    public const STRAPI_DEPENDENCY_NAME = self::SCOPED_STRAPI_PACKAGE_PREFIX . 'strapi';

    public const STRAPI_COMPOSER_PACKAGE_PREFIX = 'strapi/';

    /** the published strapi-php package: every `strapi/*` package in one (`replace`) */
    public const STRAPI_COMPOSER_DEPENDENCY_NAME = 'hynding/strapi-php';

    /** `hynding/strapi-php`, or a `strapi/*` package (a project or plugin may require those by name) */
    public static function isStrapiComposerPackage(string $name): bool
    {
        return $name === self::STRAPI_COMPOSER_DEPENDENCY_NAME || str_starts_with($name, self::STRAPI_COMPOSER_PACKAGE_PREFIX);
    }

    public static function isScopedStrapiPackage(string $name): bool
    {
        return str_starts_with($name, self::SCOPED_STRAPI_PACKAGE_PREFIX);
    }
}
