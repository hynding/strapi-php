<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

return static fn (array $options, Strapi $strapi): callable => static function (Context $ctx, callable $next): void {
    $ctx->setHeader('X-Strapi-Test', 'Address Middleware');
    $next();
};
