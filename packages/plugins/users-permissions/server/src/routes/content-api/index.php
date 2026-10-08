<?php

declare(strict_types=1);

/**
 * Port of server/src/routes/content-api/index.js. Upstream builds the routes lazily with
 * `createContentApiRoutesFactory`; this file returns the router (`type: 'content-api'`).
 */
return [
    'type' => 'content-api',
    'routes' => [
        ...(require __DIR__ . '/auth.php')(),
        ...(require __DIR__ . '/user.php')(),
        ...(require __DIR__ . '/role.php')(),
        ...(require __DIR__ . '/permissions.php')(),
    ],
];
