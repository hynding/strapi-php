<?php

declare(strict_types=1);

/** Port of server/src/routes/index.ts. */

use Strapi\Plugin\Documentation\Middlewares\RestrictAccess;

$restrictAccess = new RestrictAccess();

return [
    [
        'method' => 'GET',
        'path' => '/',
        'handler' => 'documentation.index',
        'config' => [
            'auth' => false,
            'middlewares' => [$restrictAccess],
        ],
    ],
    [
        'method' => 'GET',
        'path' => '/v:major(\\d+).:minor(\\d+).:patch(\\d+)',
        'handler' => 'documentation.index',
        'config' => [
            'auth' => false,
            'middlewares' => [$restrictAccess],
        ],
    ],
    [
        'method' => 'GET',
        'path' => '/login',
        'handler' => 'documentation.loginView',
        'config' => [
            'auth' => false,
        ],
    ],
    [
        'method' => 'POST',
        'path' => '/login',
        'handler' => 'documentation.login',
        'config' => [
            'auth' => false,
        ],
    ],
    [
        'method' => 'GET',
        'path' => '/getInfos',
        'handler' => 'documentation.getInfos',
        'config' => [
            'policies' => [
                ['name' => 'admin::hasPermissions', 'config' => ['actions' => ['plugin::documentation.read']]],
            ],
        ],
    ],
    [
        'method' => 'POST',
        'path' => '/regenerateDoc',
        'handler' => 'documentation.regenerateDoc',
        'config' => [
            'policies' => [
                [
                    'name' => 'admin::hasPermissions',
                    'config' => ['actions' => ['plugin::documentation.settings.regenerate']],
                ],
            ],
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/updateSettings',
        'handler' => 'documentation.updateSettings',
        'config' => [
            'policies' => [
                [
                    'name' => 'admin::hasPermissions',
                    'config' => ['actions' => ['plugin::documentation.settings.update']],
                ],
            ],
        ],
    ],
    [
        'method' => 'DELETE',
        'path' => '/deleteDoc/:version',
        'handler' => 'documentation.deleteDoc',
        'config' => [
            'policies' => [],
        ],
    ],
];
