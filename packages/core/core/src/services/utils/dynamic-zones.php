<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Utils;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/utils/dynamic-zones.ts. */
final class DynamicZones
{
    public static function getNumberOfDynamicZones(Strapi $strapi): int
    {
        $count = 0;
        foreach ($strapi->contentTypes() as $contentType) {
            foreach ($contentType->attributes as $attribute) {
                if (($attribute['type'] ?? null) === 'dynamiczone') {
                    $count++;
                }
            }
        }

        return $count;
    }
}
