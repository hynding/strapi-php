<?php

declare(strict_types=1);

/**
 * Port of server/src/homepage/routes/index.ts.
 *
 * The routes will be merged with the other Content Manager routers,
 * so we need to avoid conficts in the router name, and to prefix the path for each route.
 */

return [
    'homepage' => require __DIR__ . '/homepage.php',
];
