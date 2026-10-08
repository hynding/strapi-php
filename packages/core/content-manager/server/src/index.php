<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the content-manager plugin module. */

use Strapi\ContentManager\Bootstrap;
use Strapi\ContentManager\Destroy;
use Strapi\ContentManager\Register;
use Strapi\Core\Strapi;

return [
    'register' => static function (Strapi $strapi): void {
        (new Register())($strapi);
    },
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'destroy' => static function (Strapi $strapi): void {
        (new Destroy())($strapi);
    },
    'controllers' => require __DIR__ . '/controllers/index.php',
    'routes' => require __DIR__ . '/routes/index.php',
    'policies' => require __DIR__ . '/policies/index.php',
    'services' => require __DIR__ . '/services/index.php',
];
