<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/middlewares/response-time.ts. */
final class ResponseTime
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        return static function (Context $ctx, callable $next): void {
            $start = microtime(true);

            $next();

            $delta = (int) ceil((microtime(true) - $start) * 1000);
            $ctx->setHeader('X-Response-Time', "{$delta}ms");
        };
    }
}
