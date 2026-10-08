<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * `{{ name }}` middleware
 */
return static function (array $config, Strapi $strapi): callable {
    // Add your own logic here.
    return static function (Context $ctx, callable $next) use ($strapi): void {
        $strapi->log()->info('In {{ name }} middleware.');

        $next();
    };
};
