<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/content-api/register-functions/enums.ts */
final class Enums
{
    /** @param array{registry: TypeRegistry, strapi: Strapi, builders: BuildersInstance} $options */
    public static function registerEnumsDefinition(Schema $contentType, array $options): void
    {
        ['registry' => $registry, 'strapi' => $strapi, 'builders' => $builders] = $options;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $attributes = $contentType->attributes;

        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;
            if (!$utils->attributes->isEnumeration($attribute)) {
                continue;
            }

            $enumName = $utils->naming->getEnumName($contentType, $attributeName);
            $enumDefinition = $builders->buildEnumTypeDefinition($attribute, $enumName);

            $registry->register($enumName, $enumDefinition, [
                'kind' => Constants::KINDS['enum'],
                'contentType' => $contentType,
                'attributeName' => $attributeName,
                'attribute' => $attribute,
            ]);
        }
    }
}
