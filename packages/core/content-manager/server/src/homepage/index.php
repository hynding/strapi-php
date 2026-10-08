<?php

declare(strict_types=1);

/** Port of server/src/homepage/index.ts. */

return [
    'routes' => require __DIR__ . '/routes/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'services' => require __DIR__ . '/services/index.php',
];
