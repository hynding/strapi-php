<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the i18n plugin module. */

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Bootstrap;
use Strapi\Plugin\I18n\Register;

return [
    'register' => static function (Strapi $strapi): void {
        (new Register())($strapi);
    },
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'routes' => require __DIR__ . '/routes/index.php',
    'controllers' => require __DIR__ . '/controllers/index.php',
    'contentTypes' => require __DIR__ . '/content-types/index.php',
    'services' => require __DIR__ . '/services/index.php',
];
