<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/**
 * A `sift`-like in-memory matcher for Mongo-style condition queries, restricted to the operators
 * Strapi whitelists for RBAC conditions (`casl-ability.ts` → `allowedOperations`):
 * `$or, $and, $eq, $ne, $in, $nin, $lt, $lte, $gt, $gte, $exists, $elemMatch`, plus `$not`
 * (accepted by the admin query builder) and implicit equality, dot paths and array traversal.
 *
 * Mirrors sift semantics: a query `{ a: 1 }` matches when `entity.a` is `1` or an array containing `1`;
 * nested `{ a: { b: 1 } }` where the value is an object literal without `$` keys is a *deep equality* test;
 * dot paths (`'a.b'`) walk into arrays of objects.
 *
 * @phpstan-type Query array<string, mixed>
 */
final class Sift
{
    public const ALLOWED_OPERATIONS = ['$or', '$and', '$eq', '$ne', '$in', '$nin', '$lt', '$lte', '$gt', '$gte', '$exists', '$elemMatch'];

    /** Operators understood by this matcher (the whitelist plus `$not`, which upstream's query builder maps too). */
    public const SUPPORTED_OPERATIONS = [...self::ALLOWED_OPERATIONS, '$not'];

    /**
     * Compile a query into a tester. Throws for unsupported operators (sift: "Unsupported operation: $x").
     *
     * @param array<string, mixed>|mixed $query
     * @param list<string>|null $operations
     * @return \Closure(mixed): bool
     */
    public static function createQueryTester(mixed $query, ?array $operations = null): \Closure
    {
        $operations ??= self::ALLOWED_OPERATIONS;
        self::validate($query, $operations);

        return static fn (mixed $value): bool => self::matches($query, $value, $operations);
    }

    /**
     * Walk the query once and reject any `$`-prefixed key that is not a supported operation.
     * Operands of value operators (`$eq`, `$ne`, `$in`...) are data and are not inspected.
     *
     * @param list<string> $operations
     */
    public static function validate(mixed $query, array $operations): void
    {
        if (!is_array($query)) {
            return;
        }

        foreach ($query as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, '$')) {
                if (!in_array($key, $operations, true) || !in_array($key, self::SUPPORTED_OPERATIONS, true)) {
                    throw new \InvalidArgumentException("Unsupported operation: {$key}");
                }

                // structural operators carry sub-queries: keep validating inside them
                if ($key === '$and' || $key === '$or') {
                    foreach (is_array($value) ? $value : [] as $sub) {
                        self::validate($sub, $operations);
                    }
                } elseif ($key === '$elemMatch' || $key === '$not') {
                    self::validate($value, $operations);
                }

                continue;
            }

            // a field whose value is an operator map: validate its operators; an object literal is data
            if (is_array($value) && self::hasOperatorKeys($value)) {
                self::validate($value, $operations);
            }
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function hasOperatorKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key) && str_starts_with($key, '$')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $operations
     */
    public static function matches(mixed $query, mixed $value, ?array $operations = null): bool
    {
        $operations ??= self::ALLOWED_OPERATIONS;

        if (!is_array($query) || self::isList($query)) {
            return self::equals($value, $query);
        }

        foreach ($query as $key => $condition) {
            $key = (string) $key;

            if (str_starts_with($key, '$')) {
                if (!self::applyOperator($key, $condition, $value, $operations)) {
                    return false;
                }
                continue;
            }

            if (!self::matchField($key, $condition, $value, $operations)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $operations */
    private static function matchField(string $path, mixed $condition, mixed $value, array $operations): bool
    {
        $values = self::resolvePath($value, explode('.', $path));

        $isOperatorMap = is_array($condition) && !self::isList($condition) && $condition !== [] && self::hasOperatorKeys($condition);

        foreach ($values as [$exists, $resolved]) {
            if ($isOperatorMap) {
                if (self::matchOperatorMap($condition, $resolved, $exists, $operations)) {
                    return true;
                }
                continue;
            }

            if (self::valueMatches($resolved, $condition)) {
                return true;
            }
        }

        // `$exists: false` must also match when no value resolves at all
        if ($isOperatorMap && $values === [] && self::matchOperatorMap($condition, null, false, $operations)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $condition
     * @param list<string> $operations
     */
    private static function matchOperatorMap(array $condition, mixed $resolved, bool $exists, array $operations): bool
    {
        foreach ($condition as $op => $operand) {
            $op = (string) $op;
            if ($op === '$exists') {
                if ((bool) $operand !== $exists) {
                    return false;
                }
                continue;
            }
            if (!self::applyOperator($op, $operand, $resolved, $operations)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a dot path, fanning out through arrays like sift does.
     *
     * @param list<string> $segments
     * @return list<array{0: bool, 1: mixed}>  [exists, value] pairs
     */
    private static function resolvePath(mixed $value, array $segments): array
    {
        if ($segments === []) {
            return [[true, $value]];
        }

        [$head, $rest] = [$segments[0], array_slice($segments, 1)];

        if (is_array($value) && self::isList($value)) {
            // numeric index into a list, else fan out over its items
            if (preg_match('/^\d+$/', $head) === 1 && array_key_exists((int) $head, $value)) {
                return self::resolvePath($value[(int) $head], $rest);
            }
            $out = [];
            foreach ($value as $item) {
                if (is_array($item) || is_object($item)) {
                    foreach (self::resolvePath($item, $segments) as $pair) {
                        $out[] = $pair;
                    }
                }
            }

            return $out;
        }

        $has = false;
        $next = null;
        if (is_array($value) && array_key_exists($head, $value)) {
            $has = true;
            $next = $value[$head];
        } elseif (is_object($value) && (property_exists($value, $head) || isset($value->{$head}))) {
            $has = true;
            $next = $value->{$head};
        }

        if (!$has) {
            return $rest === [] ? [[false, null]] : [];
        }

        return self::resolvePath($next, $rest);
    }

    /** Implicit equality: equal, or the value is an array containing an equal item (sift semantics). */
    private static function valueMatches(mixed $resolved, mixed $condition): bool
    {
        if (self::equals($resolved, $condition)) {
            return true;
        }

        if (is_array($resolved) && self::isList($resolved) && !(is_array($condition) && self::isList($condition))) {
            foreach ($resolved as $item) {
                if (self::equals($item, $condition)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $operations */
    private static function applyOperator(string $op, mixed $operand, mixed $value, array $operations): bool
    {
        return match ($op) {
            '$and' => self::every(is_array($operand) ? $operand : [], $value, $operations),
            '$or' => self::some(is_array($operand) ? $operand : [], $value, $operations),
            '$not' => !self::matches($operand, $value, $operations),
            '$eq' => self::valueMatches($value, $operand),
            '$ne' => !self::valueMatches($value, $operand),
            '$in' => self::in($value, is_array($operand) ? $operand : [$operand]),
            '$nin' => !self::in($value, is_array($operand) ? $operand : [$operand]),
            '$gt' => self::compare($value, $operand, static fn (int $c): bool => $c > 0),
            '$gte' => self::compare($value, $operand, static fn (int $c): bool => $c >= 0),
            '$lt' => self::compare($value, $operand, static fn (int $c): bool => $c < 0),
            '$lte' => self::compare($value, $operand, static fn (int $c): bool => $c <= 0),
            '$exists' => ((bool) $operand) === ($value !== null),
            '$elemMatch' => self::elemMatch($operand, $value, $operations),
            default => throw new \InvalidArgumentException("Unsupported operation: {$op}"),
        };
    }

    /**
     * @param array<mixed> $queries
     * @param list<string> $operations
     */
    private static function every(array $queries, mixed $value, array $operations): bool
    {
        foreach ($queries as $query) {
            if (!self::matches($query, $value, $operations)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $queries
     * @param list<string> $operations
     */
    private static function some(array $queries, mixed $value, array $operations): bool
    {
        foreach ($queries as $query) {
            if (self::matches($query, $value, $operations)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $candidates */
    private static function in(mixed $value, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (self::valueMatches($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @param callable(int): bool $test */
    private static function compare(mixed $value, mixed $operand, callable $test): bool
    {
        if (is_array($value) && self::isList($value)) {
            foreach ($value as $item) {
                if (self::compare($item, $operand, $test)) {
                    return true;
                }
            }

            return false;
        }

        if ($value === null || $operand === null) {
            return false;
        }

        if ($value instanceof \DateTimeInterface || $operand instanceof \DateTimeInterface) {
            $a = $value instanceof \DateTimeInterface ? $value->getTimestamp() : self::toTimestamp($value);
            $b = $operand instanceof \DateTimeInterface ? $operand->getTimestamp() : self::toTimestamp($operand);
            if ($a === null || $b === null) {
                return false;
            }

            return $test($a <=> $b);
        }

        if ((is_int($value) || is_float($value) || is_numeric($value)) && (is_int($operand) || is_float($operand) || is_numeric($operand))) {
            return $test((float) $value <=> (float) $operand);
        }

        if (is_string($value) && is_string($operand)) {
            return $test(strcmp($value, $operand) <=> 0);
        }

        return false;
    }

    private static function toTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $ts = strtotime($value);

            return $ts === false ? null : $ts;
        }

        return null;
    }

    /** @param list<string> $operations */
    private static function elemMatch(mixed $query, mixed $value, array $operations): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::matches($query, $item, $operations)) {
                return true;
            }
        }

        return false;
    }

    /** sift equality: strict for scalars with JS-style number/string leniency, deep for arrays/objects. */
    public static function equals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->getTimestamp() === $b->getTimestamp();
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        if (is_object($a) && !$a instanceof \Closure) {
            $a = get_object_vars($a);
        }
        if (is_object($b) && !$b instanceof \Closure) {
            $b = get_object_vars($b);
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $item) {
                if (!array_key_exists($key, $b) || !self::equals($item, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** @param array<array-key, mixed> $value */
    private static function isList(array $value): bool
    {
        return $value !== [] && array_is_list($value);
    }
}
