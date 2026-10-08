<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services\Permissions;

use Strapi\Admin\Services\Permission;
use Strapi\Admin\Services\Role;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/permissions/conditions.ts (not registered by the plugin upstream).
 *
 * The engine calls a condition handler with one array: the user merged with `permission`
 * (upstream: `handler(user, { permission })`).
 */
final class Conditions
{
    /** @return list<array<string, mixed>> */
    public static function conditions(Strapi $strapi): array
    {
        return [
            [
                'displayName' => 'Has Locale Access',
                'name' => 'has-locale-access',
                'plugin' => 'i18n',
                'handler' => static function (array $user) use ($strapi): bool|array {
                    $permission = is_array($user['permission'] ?? null) ? $user['permission'] : [];
                    $properties = is_array($permission['properties'] ?? null) ? $permission['properties'] : [];
                    $locales = $properties['locales'] ?? null;
                    $role = $strapi->service('admin::role');
                    assert($role instanceof Role);
                    $superAdminCode = $role->constants['superAdminCode'];

                    $isSuperAdmin = false;
                    foreach (is_array($user['roles'] ?? null) ? $user['roles'] : [] as $userRole) {
                        if (is_array($userRole) && ($userRole['code'] ?? null) === $superAdminCode) {
                            $isSuperAdmin = true;
                            break;
                        }
                    }

                    if ($isSuperAdmin) {
                        return true;
                    }

                    return [
                        'locale' => [
                            '$in' => is_array($locales) ? $locales : [],
                        ],
                    ];
                },
            ],
        ];
    }

    public static function registerI18nConditions(Strapi $strapi): void
    {
        $permission = $strapi->service('admin::permission');
        assert($permission instanceof Permission);

        $permission->conditionProvider->registerMany(self::conditions($strapi));
    }
}
