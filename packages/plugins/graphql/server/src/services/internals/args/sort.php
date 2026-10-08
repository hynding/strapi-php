<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Args;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/services/internals/args/sort.ts */
final class Sort
{
    public static function create(): ArgDef
    {
        return Nexus::arg([
            'type' => Nexus::list('String'),
            'default' => [],
        ]);
    }
}
