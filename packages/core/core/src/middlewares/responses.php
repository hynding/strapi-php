<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/middlewares/responses.ts: `config.handlers[status]` callbacks. */
final class Responses
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $handlers = is_array($config['handlers'] ?? null) ? $config['handlers'] : [];

        return static function (Context $ctx, callable $next) use ($handlers): void {
            $next();

            $handler = $handlers[$ctx->status()] ?? null;

            if (is_callable($handler)) {
                $handler($ctx, $next);
            }
        };
    }
}
