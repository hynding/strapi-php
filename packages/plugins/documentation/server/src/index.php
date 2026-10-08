<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the documentation plugin module. */

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Bootstrap;
use Strapi\Plugin\Documentation\Register;

return [
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'config' => require __DIR__ . '/config/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'register' => static function (Strapi $strapi): void {
        (new Register())($strapi);
    },
    'services' => require __DIR__ . '/services/index.php',
];
