<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Utils;

/** Port of server/src/utils/typeguards.ts. */
final class Typeguards
{
    /**
     * `'default' in attribute`
     *
     * @param array<string, mixed> $attribute
     */
    public static function hasDefaultAttribute(array $attribute): bool
    {
        return array_key_exists('default', $attribute);
    }
}
