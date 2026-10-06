<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Utils;

/** Port of transform/relations/utils/data.ts. */
final class Data
{
    public static function isShortHand(mixed $relation): bool
    {
        return is_string($relation) || is_int($relation);
    }

    public static function isLongHand(mixed $relation): bool
    {
        return is_array($relation) && !array_is_list($relation) && (array_key_exists('id', $relation) || array_key_exists('documentId', $relation));
    }
}
