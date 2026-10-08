<?php

/**
 * Server entry: assembles the plugin module from the shared JSON spec and the hand-written code.
 * Mirrors index.ts line for line. Named in composer.json as `extra.strapi.server`.
 */

declare(strict_types=1);

return [
    'config' => require __DIR__ . '/config/index.php',
    'contentTypes' => require __DIR__ . '/content-types/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'services' => require __DIR__ . '/services/index.php',
];
