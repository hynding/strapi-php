<?php

declare(strict_types=1);

/** Port of server/src/policies/index.ts. */

return [
    'isAuthenticatedAdmin' => require __DIR__ . '/isAuthenticatedAdmin.php',
    'hasPermissions' => require __DIR__ . '/hasPermissions.php',
    'isTelemetryEnabled' => require __DIR__ . '/isTelemetryEnabled.php',
];
