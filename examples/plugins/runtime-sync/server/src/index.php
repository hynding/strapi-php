<?php

declare(strict_types=1);

/**
 * Server entry: assembles the plugin module. Mirrors index.js line for line. Named in
 * composer.json as `extra.strapi.server`.
 */

use StrapiPlugin\RuntimeSync\Register;

return [
    'register' => new Register(),
    'config' => require __DIR__ . '/config/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'services' => require __DIR__ . '/services/index.php',
];
