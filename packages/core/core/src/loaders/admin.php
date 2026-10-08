<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Loaders\Plugins\GetEnabledPlugins;
use Strapi\Core\Strapi;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Core\State;
use Strapi\Utils\Policy\PolicyContext;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/loaders/admin.ts.
 *
 * The admin module (`strapi/admin`'s strapi-server.php, added to the container by the admin
 * provider) has its services, controllers, content types, policies and middlewares registered
 * under `admin::` exactly as upstream does, and its config merged under the user's `config/admin.php`.
 *
 * PHP-port fallbacks, for an app without `strapi/admin` (the module is then `[]`):
 *
 * - Built-in schemas: every Strapi database holds `admin::user` (creator fields target it),
 *   `plugin::upload.file` / `plugin::upload.folder` (media attributes are morph relations to files)
 *   and the users-permissions user/role/permission tables. Upstream gets them from the admin, upload
 *   and users-permissions packages; the ones missing from the registries once the admin module is
 *   loaded are registered here from {@see SchemaFactory::builtinSchemas()} so the database schema
 *   stays identical.
 * - Built-in policies: plugin admin routes name `admin::isAuthenticatedAdmin` (nearly every upstream
 *   plugin does). The ones `strapi/admin` would define are registered here; the module's own win.
 *   See {@see self::builtinPolicies()}.
 */
final class Admin
{
    public function __invoke(Strapi $strapi): void
    {
        self::loadAdmin($strapi);
    }

    public static function loadAdmin(Strapi $strapi): void
    {
        $admin = $strapi->has('admin') ? $strapi->get('admin') : null;
        $admin = is_array($admin) ? $admin : [];

        $strapi->get('services')->add('admin::', $admin['services'] ?? []);
        $strapi->get('controllers')->add('admin::', $admin['controllers'] ?? []);
        $strapi->get('content-types')->add('admin::', self::formatContentTypes($admin['contentTypes'] ?? []));
        self::registerBuiltinSchemas($strapi);
        $strapi->get('policies')->add('admin::', [...self::builtinPolicies(), ...($admin['policies'] ?? [])]);
        $strapi->get('middlewares')->add('admin::', $admin['middlewares'] ?? []);

        $userAdminConfig = $strapi->config()->get('admin', []);
        $strapi->get('config')->set('admin', Objects::merge([], $admin['config'] ?? [], is_array($userAdminConfig) ? $userAdminConfig : []));
    }

    /**
     * The installed `strapi/admin` package's `strapi-server.php` (the `@strapi/admin/strapi-server`
     * export), or null when the package is not installed.
     */
    public static function adminServerFile(Strapi $strapi): ?string
    {
        $path = GetEnabledPlugins::installedPackages($strapi)['strapi/admin']['path'] ?? null;

        // the Composer runtime API knows the package even when this file is loaded through a
        // path-repository symlink (vendor/strapi/core → packages/core/core)
        if ($path === null && class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('strapi/admin')) {
            $path = \Composer\InstalledVersions::getInstallPath('strapi/admin');
        }

        if ($path === null) {
            return null;
        }

        $file = $path . '/strapi-server.php';

        return is_file($file) ? $file : null;
    }

    /** Whether the container holds a real admin module (not the PHP-port fallback `[]`). */
    public static function hasAdminModule(Strapi $strapi): bool
    {
        $admin = $strapi->has('admin') ? $strapi->get('admin') : null;

        return is_array($admin) && $admin !== [];
    }

    /**
     * Policies upstream's admin package defines, ported as-is; the admin module's own win.
     *
     * @return array<string, callable>
     */
    private static function builtinPolicies(): array
    {
        return [
            // packages/core/admin/server/src/policies/isAuthenticatedAdmin.ts
            'isAuthenticatedAdmin' => static function (PolicyContext $policyCtx): bool {
                $state = $policyCtx['state'];

                return $state instanceof State && $state->isAuthenticated();
            },
        ];
    }

    private static function registerBuiltinSchemas(Strapi $strapi): void
    {
        $registry = $strapi->get('content-types');
        foreach (SchemaFactory::builtinSchemas() as $uid => $schema) {
            if ($registry->get($uid) === null) {
                $registry->set($uid, $schema);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $contentTypes
     * @return array<string, array<string, mixed>>
     */
    private static function formatContentTypes(array $contentTypes): array
    {
        foreach ($contentTypes as $name => $definition) {
            $schema = $definition['schema'] ?? [];
            $schema['plugin'] = 'admin';
            $schema['globalId'] = \Strapi\Core\Domain\ContentType\ContentType::getGlobalId($schema, 'admin');
            $contentTypes[$name]['schema'] = $schema;
        }

        return $contentTypes;
    }
}
