<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentStructure\Utils;

/** Port of packages/core/core/src/services/content-structure/utils/isGroupExpressionValid.ts. */
final class IsGroupExpressionValid
{
    /** A record with a non-empty string `id`, a string `name`, a string or null `parent` and a `children` array. */
    public static function isGroupExpressionValid(mixed $rawGroupExpression): bool
    {
        if (!is_array($rawGroupExpression) || ($rawGroupExpression !== [] && array_is_list($rawGroupExpression))) {
            return false;
        }

        if (!is_string($rawGroupExpression['id'] ?? null)) {
            return false;
        }
        if ($rawGroupExpression['id'] === '') {
            return false;
        }

        if (!is_string($rawGroupExpression['name'] ?? null)) {
            return false;
        }

        if (!array_key_exists('parent', $rawGroupExpression) || ($rawGroupExpression['parent'] !== null && !is_string($rawGroupExpression['parent']))) {
            return false;
        }

        if (!is_array($rawGroupExpression['children'] ?? null) || !array_is_list($rawGroupExpression['children'])) {
            return false;
        }

        return true;
    }
}
