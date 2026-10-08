<?php

declare(strict_types=1);

/**
 * Plugin server methods
 */
return [
    /**
     * Application methods
     */
    'register' => require __DIR__ . '/register.php',
    'bootstrap' => require __DIR__ . '/bootstrap.php',
    'destroy' => require __DIR__ . '/destroy.php',

    'config' => require __DIR__ . '/config/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'services' => require __DIR__ . '/services/index.php',
    'contentTypes' => require __DIR__ . '/content-types/index.php',
    'policies' => require __DIR__ . '/policies/index.php',
    'middlewares' => require __DIR__ . '/middlewares/index.php',
];
