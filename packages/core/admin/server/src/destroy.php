<?php

declare(strict_types=1);

namespace Strapi\Admin;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;

/** Port of server/src/destroy.ts. */
final class Destroy
{
    public function __invoke(Strapi $strapi): void
    {
        // PHP port: an instance destroyed before `register()` (e.g. the build context) has no admin
        // services; upstream would throw on the missing service.
        if ($strapi->get('services')->get('admin::permission') === null) {
            return;
        }

        $permission = Utils::getService($strapi, 'permission');

        $permission->conditionProvider->clear();
        $permission->actionProvider->clear();
    }
}
