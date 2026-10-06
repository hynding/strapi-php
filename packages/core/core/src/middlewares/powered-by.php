<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/middlewares/powered-by.ts. */
final class PoweredBy
{
    private const DEFAULTS = ['poweredBy' => 'Strapi <strapi.io>'];

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $poweredBy = (string) ([...self::DEFAULTS, ...$config]['poweredBy']);

        return static function (Context $ctx, callable $next) use ($poweredBy): void {
            $next();

            $ctx->setHeader('X-Powered-By', $poweredBy);
        };
    }
}
