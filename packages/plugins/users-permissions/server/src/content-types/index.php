<?php

declare(strict_types=1);

/** Port of server/src/content-types/index.js. */
return [
    'permission' => ['schema' => require __DIR__ . '/permission/index.php'],
    'role' => ['schema' => require __DIR__ . '/role/index.php'],
    'user' => ['schema' => require __DIR__ . '/user/index.php'],
];
