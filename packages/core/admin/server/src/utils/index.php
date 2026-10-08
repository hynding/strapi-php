<?php

declare(strict_types=1);

namespace Strapi\Admin\Utils;

use Strapi\Admin\Services;
use Strapi\Core\Strapi;

/**
 * Port of server/src/utils/index.js (+ index.d.ts): `getService(name)` =
 * `strapi.service('admin::' + name)`. The conditional return type mirrors index.d.ts's
 * `getService<T extends keyof S>` for static analysis; at runtime any registered (or replaced)
 * service object is returned.
 */
final class Utils
{
    /**
     * @return ($name is 'user' ? Services\User : ($name is 'auth' ? Services\Auth : ($name is 'token' ? Services\Token : ($name is 'passport' ? Services\Passport : ($name is 'metrics' ? Services\Metrics : ($name is 'encryption' ? Services\Encryption : ($name is 'role' ? Services\Role : ($name is 'permission' ? Services\Permission : ($name is 'constants' ? Services\Constants : ($name is 'project-settings' ? Services\ProjectSettings : ($name is 'api-token'|'api-token-content-api'|'api-token-admin' ? Services\ApiToken : ($name is 'transfer' ? Services\Transfer\Transfer : object))))))))))))
     */
    public static function getService(Strapi $strapi, string $name): object
    {
        return $strapi->service("admin::{$name}");
    }
}
