<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/content-api/register-functions/component.ts */
final class Component
{
    /** @param array{registry: TypeRegistry, strapi: Strapi, builders: BuildersInstance} $options */
    public static function registerComponent(Schema $contentType, array $options): void
    {
        ['registry' => $registry, 'strapi' => $strapi, 'builders' => $builders] = $options;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $name = $utils->naming->getComponentName($contentType);
        $definition = $builders->buildTypeDefinition($contentType);

        $registry->register($name, $definition, ['kind' => Constants::KINDS['component'], 'contentType' => $contentType]);
    }
}
