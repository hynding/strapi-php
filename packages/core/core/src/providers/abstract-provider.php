<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Strapi;

/** Empty default implementations so each provider only defines the hooks it needs (`defineProvider`). */
abstract class AbstractProvider implements Provider
{
    public function init(Strapi $strapi): void
    {
    }

    public function register(Strapi $strapi): void
    {
    }

    public function bootstrap(Strapi $strapi): void
    {
    }

    public function destroy(Strapi $strapi): void
    {
    }
}
