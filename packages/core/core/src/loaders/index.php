<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/loaders/index.ts (`loadApplicationContext`). */
final class Loaders
{
    public static function loadApplicationContext(Strapi $strapi): void
    {
        (new SrcIndex())($strapi);
        (new Sanitizers())($strapi);
        (new Validators())($strapi);
        (new Plugins\Plugins())($strapi);
        (new Apis())($strapi);
        (new Components())($strapi);
        (new Middlewares())($strapi);
        (new Policies())($strapi);
    }
}
