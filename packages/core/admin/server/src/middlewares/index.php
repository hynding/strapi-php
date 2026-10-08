<?php

declare(strict_types=1);

/** Port of server/src/middlewares/index.ts. */

return [
    'rateLimit' => require __DIR__ . '/rateLimit.php',
    'data-transfer' => require __DIR__ . '/data-transfer.php',
];
