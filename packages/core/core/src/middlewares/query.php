<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Qs;

/**
 * Port of packages/core/core/src/middlewares/query.ts: parse the query string with `qs`
 * (`strictNullHandling`, `arrayLimit: 100`, `depth: 20`) into `$ctx->query()`.
 */
final class Query
{
    private const DEFAULTS = ['strictNullHandling' => true, 'arrayLimit' => 100, 'depth' => 20];

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $settings = [...self::DEFAULTS, ...$config];

        return static function (Context $ctx, callable $next) use ($settings): void {
            $ctx->setQuery(Qs::parse($ctx->querystring(), $settings));
            $next();
        };
    }
}
