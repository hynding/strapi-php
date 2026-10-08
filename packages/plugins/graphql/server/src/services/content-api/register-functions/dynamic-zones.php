<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/content-api/register-functions/dynamic-zones.ts */
final class DynamicZones
{
    /** @param array{registry: TypeRegistry, strapi: Strapi, builders: BuildersInstance} $options */
    public static function registerDynamicZonesDefinition(Schema $contentType, array $options): void
    {
        ['registry' => $registry, 'strapi' => $strapi, 'builders' => $builders] = $options;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $naming = $utils->naming;

        $attributes = $contentType->attributes;

        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;
            if (!$utils->attributes->isDynamicZone($attribute)) {
                continue;
            }

            $dzName = $naming->getDynamicZoneName($contentType, $attributeName);
            $dzInputName = $naming->getDynamicZoneInputName($contentType, $attributeName);

            [$type, $input] = $builders->buildDynamicZoneDefinition($attribute, $dzName, $dzInputName);

            $baseConfig = [
                'contentType' => $contentType,
                'attributeName' => $attributeName,
                'attribute' => $attribute,
            ];

            $registry->register($dzName, $type, ['kind' => Constants::KINDS['dynamicZone'], ...$baseConfig]);
            $registry->register($dzInputName, $input, ['kind' => Constants::KINDS['input'], ...$baseConfig]);
        }
    }
}
