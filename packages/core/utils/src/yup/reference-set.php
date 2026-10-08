<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/** yup's `ReferenceSet`: the `oneOf`/`notOneOf` values (a JS `Set`) plus refs by key. */
final class ReferenceSet
{
    /** @var list<mixed> */
    private array $list = [];

    /** @var array<string, Reference> */
    private array $refs = [];

    public function size(): int
    {
        return count($this->list) + count($this->refs);
    }

    /** @return list<mixed> */
    public function describe(): array
    {
        return [...$this->list, ...array_values(array_map(static fn (Reference $r): array => $r->describe(), $this->refs))];
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        return [...$this->list, ...array_values($this->refs)];
    }

    /**
     * `toArray().join(', ')`. Unlike JS, null is written "null" (as the entity validator's
     * enumeration message has always shown it) rather than an empty string.
     */
    public function join(): string
    {
        return implode(', ', array_map(static fn (mixed $v): string => $v instanceof Undefined ? '' : Yup::jsString($v), $this->toArray()));
    }

    public function add(mixed $value): void
    {
        if ($value instanceof Reference) {
            $this->refs[$value->key] = $value;

            return;
        }
        if (!$this->listHas($value)) {
            $this->list[] = $value;
        }
    }

    public function delete(mixed $value): void
    {
        if ($value instanceof Reference) {
            unset($this->refs[$value->key]);

            return;
        }
        $this->list = array_values(array_filter($this->list, static fn (mixed $v): bool => !self::sameValueZero($v, $value)));
    }

    /** @param callable(mixed): mixed $resolve */
    public function has(mixed $value, callable $resolve): bool
    {
        if ($this->listHas($value)) {
            return true;
        }
        foreach ($this->refs as $ref) {
            if (self::sameValueZero($resolve($ref), $value)) {
                return true;
            }
        }

        return false;
    }

    public function merge(self $newItems, self $removeItems): self
    {
        $next = clone $this;
        foreach ($newItems->toArray() as $value) {
            $next->add($value);
        }
        foreach ($removeItems->toArray() as $value) {
            $next->delete($value);
        }

        return $next;
    }

    private function listHas(mixed $value): bool
    {
        foreach ($this->list as $v) {
            if (self::sameValueZero($v, $value)) {
                return true;
            }
        }

        return false;
    }

    /** JS `Set` equality: like `===` but NaN equals NaN. */
    private static function sameValueZero(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_float($b) && is_nan($a) && is_nan($b)) {
            return true;
        }

        return Yup::sameValue($a, $b);
    }
}
