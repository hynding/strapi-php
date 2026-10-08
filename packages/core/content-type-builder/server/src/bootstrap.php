<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder;

use Strapi\Admin\Services\Permission as PermissionService;
use Strapi\Core\Strapi;

/** Port of server/src/bootstrap.ts: registers the `plugin::content-type-builder.read` action. */
final class Bootstrap
{
    public function __invoke(Strapi $strapi): void
    {
        $actions = [
            [
                'section' => 'plugins',
                'displayName' => 'Read',
                'uid' => 'read',
                'pluginName' => 'content-type-builder',
            ],
        ];

        /** @var PermissionService $permission */
        $permission = $strapi->service('admin::permission');
        $permission->actionProvider->registerMany($actions);
    }
}
