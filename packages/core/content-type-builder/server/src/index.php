<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the content-type-builder plugin module. */

use Strapi\ContentTypeBuilder\Bootstrap;
use Strapi\ContentTypeBuilder\Register;
use Strapi\Core\Strapi;

return [
    'config' => require __DIR__ . '/config.php',
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'register' => static function (Strapi $strapi): void {
        (new Register())($strapi);
    },
    'services' => require __DIR__ . '/services/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'middlewares' => require __DIR__ . '/middlewares/index.php',
];
