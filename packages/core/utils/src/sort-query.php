<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/utils/src/sort-query.ts.
 *
 * @phpstan-type SortParamsObject array<string, mixed>
 */
final class SortQuery
{
    /**
     * Splits a REST sort string into trimmed segments with a non-empty field (drops '', ',', trailing commas).
     *
     * @return list<string>
     */
    public static function getMeaningfulSortSegments(string $sort): array
    {
        $out = [];
        foreach (explode(',', $sort) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $field = explode(':', $segment)[0];
            if (trim($field) !== '') {
                $out[] = $segment;
            }
        }

        return $out;
    }

    /** @param SortParamsObject $sort */
    private static function hasMeaningfulSortObject(array $sort): bool
    {
        foreach ($sort as $order) {
            if (is_string($order) && $order !== '') {
                return true;
            }
            if (Objects::isPlainObject($order) && self::hasMeaningfulSortObject($order)) {
                return true;
            }
        }

        return false;
    }

    private static function hasMeaningfulStringSort(string $sort): bool
    {
        return self::getMeaningfulSortSegments($sort) !== [];
    }

    /** Whether `sort` carries a real ordering instruction (empty, `[null]`, `{}`, `''` do not). */
    public static function hasSort(mixed $sort): bool
    {
        if ($sort === null) {
            return false;
        }

        if (is_string($sort)) {
            return self::hasMeaningfulStringSort($sort);
        }

        if (is_array($sort) && array_is_list($sort)) {
            if ($sort === []) {
                return false;
            }
            foreach ($sort as $item) {
                if (is_string($item) && self::hasMeaningfulStringSort($item)) {
                    return true;
                }
                if (Objects::isPlainObject($item) && self::hasMeaningfulSortObject($item)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($sort)) {
            return self::hasMeaningfulSortObject($sort);
        }

        return false;
    }
}
