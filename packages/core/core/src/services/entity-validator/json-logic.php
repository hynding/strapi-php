<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/**
 * Not an upstream file: the subset of `json-logic-js` the entity validator needs to evaluate
 * `attribute.conditions.visible` (`var`, `==`, `===`, `!=`, `!==`, `!`, `!!`, `and`, `or`, `in`, `>`, `>=`, `<`, `<=`, `if`).
 */
final class JsonLogic
{
    /** @param array<string, mixed> $data */
    public static function apply(mixed $logic, array $data = []): mixed
    {
        if (!is_array($logic) || array_is_list($logic)) {
            return $logic;
        }
        if (count($logic) !== 1) {
            return $logic;
        }

        $op = (string) array_key_first($logic);
        $values = $logic[$op];
        $values = is_array($values) && array_is_list($values) ? $values : [$values];

        if ($op === 'if') {
            for ($i = 0; $i < count($values) - 1; $i += 2) {
                if (self::truthy(self::apply($values[$i], $data))) {
                    return self::apply($values[$i + 1], $data);
                }
            }

            return count($values) % 2 === 1 ? self::apply($values[count($values) - 1], $data) : null;
        }
        if ($op === 'and') {
            $result = true;
            foreach ($values as $value) {
                $result = self::apply($value, $data);
                if (!self::truthy($result)) {
                    return $result;
                }
            }

            return $result;
        }
        if ($op === 'or') {
            $result = false;
            foreach ($values as $value) {
                $result = self::apply($value, $data);
                if (self::truthy($result)) {
                    return $result;
                }
            }

            return $result;
        }

        $args = array_map(static fn (mixed $v): mixed => self::apply($v, $data), $values);

        return match ($op) {
            'var' => self::variable($data, $args[0] ?? '', $args[1] ?? null),
            '==' => ($args[0] ?? null) == ($args[1] ?? null),
            '===' => ($args[0] ?? null) === ($args[1] ?? null),
            '!=' => ($args[0] ?? null) != ($args[1] ?? null),
            '!==' => ($args[0] ?? null) !== ($args[1] ?? null),
            '!' => !self::truthy($args[0] ?? null),
            '!!' => self::truthy($args[0] ?? null),
            '>' => ($args[0] ?? null) > ($args[1] ?? null),
            '>=' => ($args[0] ?? null) >= ($args[1] ?? null),
            '<' => count($args) === 3 ? ($args[0] < $args[1] && $args[1] < $args[2]) : ($args[0] ?? null) < ($args[1] ?? null),
            '<=' => count($args) === 3 ? ($args[0] <= $args[1] && $args[1] <= $args[2]) : ($args[0] ?? null) <= ($args[1] ?? null),
            'in' => is_string($args[1] ?? null) ? str_contains($args[1], (string) $args[0]) : in_array($args[0] ?? null, is_array($args[1] ?? null) ? $args[1] : [], false),
            default => throw new \RuntimeException("Unrecognized operation {$op}"),
        };
    }

    /** @param array<string, mixed> $data */
    private static function variable(array $data, mixed $path, mixed $default): mixed
    {
        if ($path === '' || $path === null) {
            return $data;
        }
        $current = $data;
        foreach (explode('.', (string) $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return $default;
            }
        }

        return $current;
    }

    public static function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        if ($value === '0') {
            return true; // JS: '0' is truthy
        }

        return (bool) $value;
    }
}
