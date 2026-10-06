<?php

declare(strict_types=1);

namespace Strapi\Utils\Primitives;

/** Port of packages/core/utils/src/primitives/arrays.ts. */
final class Arrays
{
    /**
     * @param list<mixed> $arr
     * @param callable(mixed): mixed $cast
     */
    public static function castIncludes(array $arr, mixed $val, callable $cast): bool
    {
        return in_array($cast($val), array_map($cast, $arr), true);
    }

    /** True when the stringified value is in the stringified array. */
    public static function includesString(array $arr, mixed $val): bool
    {
        return self::castIncludes(array_values($arr), $val, Strings::stringify(...));
    }
}
