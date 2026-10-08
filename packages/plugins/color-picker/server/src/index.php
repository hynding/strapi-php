<?php

declare(strict_types=1);

/** Port of server/src/index.ts: the color-picker plugin module. */

use Strapi\Core\Strapi;
use Strapi\Plugin\ColorPicker\Register;

return [
    'register' => static function (Strapi $strapi): void {
        (new Register())($strapi);
    },
];
