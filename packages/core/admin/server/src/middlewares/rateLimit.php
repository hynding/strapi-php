<?php

declare(strict_types=1);

use Strapi\Core\Strapi;

// PLACEHOLDER: not ported yet (middlewares/rateLimit.ts)
return static fn (array $config, Strapi $strapi): callable => static function (mixed $ctx, callable $next): void {
    $next();
};
