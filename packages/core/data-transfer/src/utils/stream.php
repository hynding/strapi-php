<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/stream.ts. Node object streams are PHP iterables here: a transform is a
 * closure that maps one chunk to the list of chunks it emits (none to drop it, as
 * `callback(null, undefined)` does).
 */
final class Stream
{
    /**
     * Create a filter transform that discards chunks which don't satisfy the given predicate
     *
     * @param callable(mixed): bool $predicate
     *
     * @return \Closure(mixed): list<mixed>
     */
    public static function filter(callable $predicate): \Closure
    {
        return static fn (mixed $chunk): array => $predicate($chunk) ? [$chunk] : [];
    }

    /**
     * Create a map transform that transforms chunks using the given predicate
     *
     * @param callable(mixed): mixed $predicate
     *
     * @return \Closure(mixed): list<mixed>
     */
    public static function map(callable $predicate): \Closure
    {
        return static function (mixed $chunk) use ($predicate): array {
            $mapped = $predicate($chunk);

            // `callback(null, undefined)` pushes nothing
            return $mapped === null ? [] : [$mapped];
        };
    }

    /**
     * Collect every chunk from a readable (an iterable).
     *
     * @param iterable<mixed> $stream
     *
     * @return list<mixed>
     */
    public static function collect(iterable $stream): array
    {
        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Chain transforms: each chunk goes through every transform in turn.
     *
     * @param list<\Closure(mixed): list<mixed>> $transforms
     *
     * @return \Closure(mixed): list<mixed>
     */
    public static function chain(array $transforms): \Closure
    {
        return static function (mixed $chunk) use ($transforms): array {
            $chunks = [$chunk];
            foreach ($transforms as $transform) {
                $next = [];
                foreach ($chunks as $c) {
                    foreach ($transform($c) as $out) {
                        $next[] = $out;
                    }
                }
                $chunks = $next;
            }

            return $chunks;
        };
    }
}
