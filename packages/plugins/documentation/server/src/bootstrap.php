<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation;

use Strapi\Core\Strapi;

/** Port of server/src/bootstrap.ts. */
final class Bootstrap
{
    // Add permissions
    public const RBAC_ACTIONS = [
        [
            'section' => 'plugins',
            'displayName' => 'Access the Documentation',
            'uid' => 'read',
            'pluginName' => 'documentation',
        ],
        [
            'section' => 'plugins',
            'displayName' => 'Update and delete',
            'uid' => 'settings.update',
            'pluginName' => 'documentation',
        ],
        [
            'section' => 'plugins',
            'displayName' => 'Regenerate',
            'uid' => 'settings.regenerate',
            'pluginName' => 'documentation',
        ],
        [
            'section' => 'settings',
            'displayName' => 'Access the documentation settings page',
            'uid' => 'settings.read',
            'pluginName' => 'documentation',
            'category' => 'documentation',
        ],
    ];

    public function __invoke(Strapi $strapi): void
    {
        $permissionService = $strapi->service('admin::permission');
        if (!$permissionService instanceof \Strapi\Admin\Services\Permission) {
            throw new \RuntimeException('The admin::permission service is not available');
        }
        $permissionService->actionProvider->registerMany(self::RBAC_ACTIONS);

        $pluginStore = $strapi->store()([
            'environment' => '',
            'type' => 'plugin',
            'name' => 'documentation',
        ]);

        $config = $pluginStore->get(['key' => 'config']);

        if (!$config) {
            $pluginStore->set(['key' => 'config', 'value' => ['restrictedAccess' => false]]);
        }
        if (getenv('NODE_ENV') !== 'production') {
            Utils::getService('documentation', $strapi)->generateFullDoc();
        }
    }
}
