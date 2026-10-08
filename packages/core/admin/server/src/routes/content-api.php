<?php

declare(strict_types=1);

/** Port of server/src/routes/content-api.ts. */

return [
    [
        'method' => 'GET',
        'path' => '/content-api/permissions',
        'handler' => 'content-api.getPermissions',
        'config' => [
            'policies' => [
                'admin::isAuthenticatedAdmin',
            ],
        ],
    ],
    [
        'method' => 'GET',
        'path' => '/content-api/routes',
        'handler' => 'content-api.getRoutes',
        'config' => [
            'policies' => [
                'admin::isAuthenticatedAdmin',
            ],
        ],
    ],
];
