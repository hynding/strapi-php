<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Utils;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/utils/conditional-fields.ts. */
final class ConditionalFields
{
    public static function getNumberOfConditionalFields(Strapi $strapi): int
    {
        $count = 0;
        foreach ([...$strapi->contentTypes(), ...$strapi->components()] as $schema) {
            foreach ($schema->attributes as $attribute) {
                if (isset($attribute['conditions']['visible'])) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
