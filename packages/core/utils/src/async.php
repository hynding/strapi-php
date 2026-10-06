<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/async.ts — synchronous equivalents (everything in strapi-php is synchronous).
 */
final class Async
{
    /**
     * Compose functions left to right. The first receives all arguments, the rest the previous result.
     *
     * @param callable ...$fns
     * @return callable(mixed ...$args): mixed
     */
    public static function pipe(callable ...$fns): callable
    {
        return static function (mixed ...$args) use ($fns): mixed {
            if ($fns === []) {
                return $args[0] ?? null;
            }

            $first = array_shift($fns);
            $res = $first(...$args);

            foreach ($fns as $fn) {
                $res = $fn($res);
            }

            return $res;
        };
    }

    /**
     * p-map equivalent: map over an iterable with (value, index).
     *
     * @template T
     * @template R
     * @param iterable<T> $input
     * @param callable(T, int): R $mapper
     * @param array{concurrency?: int} $options  accepted for signature parity, ignored
     * @return list<R>
     */
    public static function map(iterable $input, callable $mapper, array $options = []): array
    {
        $out = [];
        $i = 0;
        foreach ($input as $value) {
            $out[] = $mapper($value, $i);
            $i++;
        }

        return $out;
    }

    /**
     * `reduce(array)(iteratee, initial)`.
     *
     * @param list<mixed> $mixedArray
     * @return callable(callable(mixed, mixed, int): mixed, mixed=): mixed
     */
    public static function reduce(array $mixedArray): callable
    {
        return static function (callable $iteratee, mixed $initialValue = null) use ($mixedArray): mixed {
            $acc = $initialValue;
            foreach (array_values($mixedArray) as $i => $value) {
                $acc = $iteratee($acc, $value, $i);
            }

            return $acc;
        };
    }
}
