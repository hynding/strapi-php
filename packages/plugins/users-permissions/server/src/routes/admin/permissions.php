<?php

declare(strict_types=1);

/** Port of server/src/routes/admin/permissions.js. */
return [
    [
        'method' => 'GET',
        'path' => '/permissions',
        'handler' => 'permissions.getPermissions',
    ],
    [
        'method' => 'GET',
        'path' => '/policies',
        'handler' => 'permissions.getPolicies',
    ],

    [
        'method' => 'GET',
        'path' => '/routes',
        'handler' => 'permissions.getRoutes',
    ],
];
