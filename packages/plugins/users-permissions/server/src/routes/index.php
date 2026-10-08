<?php

declare(strict_types=1);

/** Port of server/src/routes/index.js. */
return [
    'admin' => require __DIR__ . '/admin/index.php',
    'content-api' => require __DIR__ . '/content-api/index.php',
];
