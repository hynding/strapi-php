<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry;

use Strapi\Core\Strapi;
use Strapi\Plugin\Sentry\Middlewares\Sentry as SentryMiddleware;

/** Port of server/src/bootstrap.ts. */
final class Bootstrap
{
    public function __invoke(Strapi $strapi): void
    {
        // Initialize the Sentry service exposed by this plugin
        (new SentryMiddleware())($strapi);
    }
}
