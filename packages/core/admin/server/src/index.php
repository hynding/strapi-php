<?php

declare(strict_types=1);

/**
 * Port of server/src/index.ts: the admin module. EE (`ee/server/src`) is not ported (not MIT),
 * so `strapi.EE` never merges anything here.
 */

use Strapi\Admin\Bootstrap;
use Strapi\Admin\Destroy;
use Strapi\Admin\Register;
use Strapi\Core\Strapi;

return [
    'bootstrap' => static fn (Strapi $strapi): mixed => (new Bootstrap())($strapi),
    'register' => static fn (Strapi $strapi): mixed => (new Register())($strapi),
    'destroy' => static fn (Strapi $strapi): mixed => (new Destroy())($strapi),
    'config' => require __DIR__ . '/config/index.php',
    'policies' => require __DIR__ . '/policies/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'services' => require __DIR__ . '/services/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'contentTypes' => require __DIR__ . '/content-types/index.php',
    'middlewares' => require __DIR__ . '/middlewares/index.php',
];
