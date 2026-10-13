<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

return static function (array $config, Strapi $strapi): callable {
    return static function (Context $ctx, callable $next): void {
        $start = microtime(true);
        $next();
        $ctx->set('X-Response-Time', round((microtime(true) - $start) * 1000) . 'ms');
    };
};
