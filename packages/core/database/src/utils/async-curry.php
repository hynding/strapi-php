<?php

declare(strict_types=1);

namespace Strapi\Database\Utils;

/**
 * Port of packages/core/database/src/utils/async-curry.ts. Everything is synchronous here, so this
 * is a plain curry: `AsyncCurry::curry($fn)($a)($b)` calls `$fn($a, $b)` once every parameter is bound.
 */
final class AsyncCurry
{
    public static function curry(callable $fn, ?int $arity = null): \Closure
    {
        $arity ??= (new \ReflectionFunction(\Closure::fromCallable($fn)))->getNumberOfParameters();

        $curried = static function (array $args) use ($fn, $arity, &$curried): mixed {
            if (count($args) >= $arity) {
                return $fn(...$args);
            }

            return static fn (mixed ...$more): mixed => $curried([...$args, ...$more]);
        };

        return static fn (mixed ...$args): mixed => $curried($args);
    }
}
