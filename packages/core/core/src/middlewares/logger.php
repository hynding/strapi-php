<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/middlewares/logger.ts: `METHOD url (ms) status` at the http (info) level. */
final class Logger
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        return static function (Context $ctx, callable $next) use ($strapi): void {
            $start = microtime(true);
            $next();
            $delta = (int) ceil((microtime(true) - $start) * 1000);

            $strapi->log()->info("{$ctx->method()} {$ctx->url()} ({$delta} ms) {$ctx->status()}");
        };
    }
}
