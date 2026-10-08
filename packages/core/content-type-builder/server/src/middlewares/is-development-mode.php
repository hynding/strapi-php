<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Middlewares;

use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\PolicyError;

/**
 * Port of server/src/middlewares/is-development-mode.ts.
 *
 * Middleware to ensure Content-Type Builder modifications only happen in development mode
 * This prevents SQL injection vulnerabilities in production by blocking schema modifications
 * when autoReload is disabled.
 *
 * Upstream's export is the `(ctx, next)` function itself (it reads the global `strapi`); routes
 * use an instance of this class, which reads {@see Core::instance()} unless given one.
 */
final class IsDevelopmentMode
{
    public function __construct(private readonly ?Strapi $strapi = null)
    {
    }

    public function __invoke(Context $ctx, callable $next): mixed
    {
        $strapi = $this->strapi ?? Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');
        $autoReload = $strapi->config()->get('autoReload');

        if ($autoReload !== true) {
            // Using a PolicyError to throw a publicly visible message in the API
            throw new PolicyError(
                'Content-Type Builder modifications are disabled in production mode. Schema changes can only be made when running with autoReload enabled (strapi develop).',
            );
        }

        return $next();
    }
}
