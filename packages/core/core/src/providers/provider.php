<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/provider.ts: the lifecycle hooks a provider may implement. */
interface Provider
{
    public function init(Strapi $strapi): void;

    public function register(Strapi $strapi): void;

    public function bootstrap(Strapi $strapi): void;

    public function destroy(Strapi $strapi): void;
}
