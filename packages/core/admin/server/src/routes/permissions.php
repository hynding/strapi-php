<?php

declare(strict_types=1);

/** Port of server/src/routes/permissions.ts. */

return [
    [
        'method' => 'GET',
        'path' => '/permissions',
        'handler' => 'permission.getAll',
        'config' => [
            'policies' => ['admin::isAuthenticatedAdmin'],
        ],
    ],
    [
        'method' => 'POST',
        'path' => '/permissions/check',
        'handler' => 'permission.check',
        'config' => [
            'policies' => ['admin::isAuthenticatedAdmin'],
        ],
    ],
];
