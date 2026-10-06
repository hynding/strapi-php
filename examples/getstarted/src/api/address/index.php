<?php

declare(strict_types=1);

use Strapi\Core\Strapi;

return [
    'register' => static function (Strapi $strapi): void {
        // $strapi->log()->info('Address API register');
    },
    'bootstrap' => static function (Strapi $strapi): void {
        // $strapi->log()->info('Address API bootstrap');
    },
];
