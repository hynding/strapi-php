<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the email plugin module. */

use Strapi\Core\Strapi;
use Strapi\Email\Bootstrap;

return [
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'services' => require __DIR__ . '/services/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'config' => require __DIR__ . '/config.php',
    'middlewares' => require __DIR__ . '/middlewares/index.php',
];
