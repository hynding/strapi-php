<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * `test-middleware` middleware
 */
return static function (array $config, Strapi $strapi): callable {
    // This middleware is called on every request
    // Add your own logic here.
    return static function (Context $ctx, callable $next): void {
        // $strapi->log()->info('In application test-middleware middleware.');

        $next();
    };
};
