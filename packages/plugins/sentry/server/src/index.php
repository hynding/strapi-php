<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the sentry plugin module. */

use Strapi\Core\Strapi;
use Strapi\Plugin\Sentry\Bootstrap;

return [
    'bootstrap' => static function (Strapi $strapi): void {
        (new Bootstrap())($strapi);
    },
    'config' => require __DIR__ . '/config.php',
    'services' => require __DIR__ . '/services/index.php',
];
