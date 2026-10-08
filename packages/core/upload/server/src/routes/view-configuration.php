<?php

declare(strict_types=1);

use Strapi\Upload\Constants;

// Port of server/src/routes/view-configuration.ts
return [
    'type' => 'admin',
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/configuration',
            'handler' => 'view-configuration.findViewConfiguration',
            'config' => [
                'policies' => ['admin::isAuthenticatedAdmin'],
            ],
        ],
        [
            'method' => 'PUT',
            'path' => '/configuration',
            'handler' => 'view-configuration.updateViewConfiguration',
            'config' => [
                'policies' => [
                    'admin::isAuthenticatedAdmin',
                    ['name' => 'admin::hasPermissions', 'config' => ['actions' => [Constants::ACTIONS['configureView']]]],
                ],
            ],
        ],
    ],
];
