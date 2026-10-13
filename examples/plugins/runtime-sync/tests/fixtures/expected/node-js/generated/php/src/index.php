<?php

declare(strict_types=1);

// use Strapi\Core\Strapi;

return [
    /**
     * A register function that runs before
     * your application is initialized.
     *
     * This gives you an opportunity to extend code.
     */
    'register' => static function (/* Strapi $strapi */): void {
    },

    /**
     * A bootstrap function that runs before
     * your application gets started.
     *
     * This gives you an opportunity to set up your data model,
     * run jobs, or perform some special logic.
     */
    'bootstrap' => static function (/* Strapi $strapi */): void {
    },
];
