<?php

declare(strict_types=1);

/** Port of server/src/routes/admin/index.js. */
return [
    'type' => 'admin',
    'routes' => [
        ...require __DIR__ . '/role.php',
        ...require __DIR__ . '/settings.php',
        ...require __DIR__ . '/permissions.php',
    ],
];
