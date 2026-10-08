<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the graphql plugin module. */

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Bootstrap;

return [
    'config' => require __DIR__ . '/config/index.php',
    'bootstrap' => static function (Strapi $strapi): void {
        Bootstrap::bootstrap($strapi);
    },
    // upstream assigns `strapi.plugin('graphql').destroy` in bootstrap (it needs the Apollo server)
    'destroy' => static function (Strapi $strapi): void {
        Bootstrap::destroy($strapi);
    },
    'services' => require __DIR__ . '/services/index.php',
];
