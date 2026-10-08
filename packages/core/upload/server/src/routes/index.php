<?php

declare(strict_types=1);

// Port of server/src/routes/index.ts
return [
    'admin' => require __DIR__ . '/admin.php',
    'content-api' => require __DIR__ . '/content-api.php',
    'viewConfiguration' => require __DIR__ . '/view-configuration.php',
];
