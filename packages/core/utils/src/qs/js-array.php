<?php

declare(strict_types=1);

namespace Strapi\Utils\Qs;

/**
 * Internal node used while parsing a query string: a JavaScript *array* (as opposed to an object
 * with numeric keys, which PHP cannot tell apart from a list). Holes are allowed (`a[2]=x` gives
 * index 2 only) and are dropped by compaction at the end, like `qs` does.
 *
 * When `arrayLimit` is exceeded, `qs` turns the array into a plain object with numeric keys and
 * remembers the max index in a side channel; here that is the `overflow` / `maxIndex` pair.
 *
 * @internal
 */
final class JsArray
{
    public bool $overflow = false;

    public int $maxIndex = -1;

    /** @param array<int, mixed> $items index → value, holes simply absent */
    public function __construct(public array $items = [])
    {
    }

    public function length(): int
    {
        return $this->items === [] ? 0 : max(array_keys($this->items)) + 1;
    }

    public function push(mixed $value): void
    {
        $this->items[$this->length()] = $value;
    }

    public function has(int $index): bool
    {
        return array_key_exists($index, $this->items);
    }

    /** @return array<int, mixed> the items keyed by index, holes dropped, sorted */
    public function toObject(): array
    {
        ksort($this->items);

        return $this->items;
    }
}
