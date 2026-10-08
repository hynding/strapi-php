<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Args;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/args/publication-filter.ts */
final class PublicationFilter
{
    public static function create(): ArgDef
    {
        return Nexus::arg([
            'type' => Constants::PUBLICATION_FILTER_TYPE_NAME,
        ]);
    }
}
