<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/loaders/admin.ts.
 *
 * STUB: the admin package (`strapi/admin`) is not ported yet. When the container holds an `admin`
 * module (registered by a future package through `strapi.add('admin', ...)`), its services,
 * controllers, content types, policies and middlewares are registered under `admin::` exactly as
 * upstream does; otherwise only the user's `config/admin.php` is kept.
 *
 * Built-in schemas: every Strapi database holds `admin::user` (creator fields target it),
 * `plugin::upload.file` / `plugin::upload.folder` (media attributes are morph relations to files)
 * and the users-permissions user/role/permission tables. Upstream gets them from the admin, upload
 * and users-permissions packages; until those are ported, the ones missing from the registries are
 * registered here from {@see SchemaFactory::builtinSchemas()} so the database schema stays identical.
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

        self::registerBuiltinSchemas($strapi);

        $strapi->get('services')->add('admin::', $admin['services'] ?? []);
        $strapi->get('controllers')->add('admin::', $admin['controllers'] ?? []);
        $strapi->get('content-types')->add('admin::', self::formatContentTypes($admin['contentTypes'] ?? []));
        $strapi->get('policies')->add('admin::', $admin['policies'] ?? []);
        $strapi->get('middlewares')->add('admin::', $admin['middlewares'] ?? []);

        $userAdminConfig = $strapi->config()->get('admin', []);
        $strapi->get('config')->set('admin', Objects::merge([], $admin['config'] ?? [], is_array($userAdminConfig) ? $userAdminConfig : []));
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
