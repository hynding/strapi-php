<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/content-api/register-functions/filters.ts */
final class Filters
{
    /** @param array{registry: TypeRegistry, strapi: Strapi, builders: BuildersInstance} $options */
    public static function registerFiltersDefinition(Schema $contentType, array $options): void
    {
        ['registry' => $registry, 'strapi' => $strapi, 'builders' => $builders] = $options;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $type = $utils->naming->getFiltersInputTypeName($contentType);
        $definition = $builders->buildContentTypeFilters($contentType);

        $registry->register($type, $definition, ['kind' => Constants::KINDS['filtersInput'], 'contentType' => $contentType]);
    }
}
