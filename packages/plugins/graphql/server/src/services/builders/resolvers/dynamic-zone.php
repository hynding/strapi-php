<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Core\Strapi;

/** Port of server/src/services/builders/resolvers/dynamic-zone.ts */
final class DynamicZone
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array{contentTypeUID: string, attributeName: string} $options */
    public function buildDynamicZoneResolver(array $options): \Closure
    {
        $strapi = $this->strapi;
        ['contentTypeUID' => $contentTypeUID, 'attributeName' => $attributeName] = $options;

        return static function (mixed $parent) use ($strapi, $contentTypeUID, $attributeName): mixed {
            if (!is_array($parent)) {
                return null;
            }

            return $strapi->db()->query($contentTypeUID)->load($parent, $attributeName);
        };
    }
}
