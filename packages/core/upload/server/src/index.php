<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the upload plugin module. */

use Strapi\Core\Strapi;
use Strapi\Upload\Bootstrap;
use Strapi\Upload\Register;

return [
    'register' => static function (Strapi $strapi): void {
        (new Register())($strapi);
    },
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'config' => require __DIR__ . '/config.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'contentTypes' => require __DIR__ . '/content-types/index.php',
    'services' => require __DIR__ . '/services/index.php',
];
