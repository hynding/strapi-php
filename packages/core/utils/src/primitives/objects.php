<?php

declare(strict_types=1);

namespace Strapi\Utils\Primitives;

/** Port of packages/core/utils/src/primitives/objects.ts. */
final class Objects
{
    private const UNSAFE_KEYS = ['__proto__', 'constructor', 'prototype'];

    /**
     * Every leaf path of a nested array, dot-joined (`['a' => ['b' => 1]]` → `['a.b']`).
     *
     * @param array<string|int, mixed> $obj
     * @param list<string> $path
     * @return list<string>
     */
    public static function keysDeep(array $obj, array $path = []): array
    {
        if ($obj === []) {
            return [implode('.', $path)];
        }

        $out = [];
        foreach ($obj as $key => $value) {
            $nextPath = [...$path, (string) $key];
            if (is_array($value) && $value !== []) {
                $out = [...$out, ...self::keysDeep($value, $nextPath)];
            } else {
                $out[] = implode('.', $nextPath);
            }
        }

        return $out;
    }

    /**
     * lodash `_.toPath`: `'a.b[0].c'` → `['a', 'b', '0', 'c']`.
     *
     * @return list<string>
     */
    public static function toPath(string $path): array
    {
        if ($path === '') {
            return [];
        }

        preg_match_all('/[^.[\]]+|\[(?:(-?\d+(?:\.\d+)?)|(["\'])((?:(?!\2)[^\\\\]|\\\\.)*?)\2)\]/', $path, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $m) {
            if (isset($m[3])) {
                $out[] = stripslashes($m[3]);
            } elseif (isset($m[1])) {
                $out[] = $m[1];
            } else {
                $out[] = $m[0];
            }
        }

        if (str_starts_with($path, '.') && ($out[0] ?? null) !== '') {
            array_unshift($out, '');
        }

        return $out;
    }

    /**
     * Sets a property without changing the input: containers along the path are copied, every
     * other value (including the assigned one) keeps its identity. A literal key matching the
     * whole path wins over splitting it.
     *
     * @param array<string|int, mixed> $object
     * @param string|list<string|int> $path
     * @return ($object is array<string, mixed> ? array<string, mixed> : array<string|int, mixed>)
     */
    public static function set(array $object, string|array $path, mixed $value): array
    {
        if (is_string($path)) {
            $segments = array_key_exists($path, $object) ? [$path] : self::toPath($path);
        } else {
            $segments = array_map(static fn (string|int $s): string => (string) $s, $path);
        }

        foreach ($segments as $segment) {
            if (in_array($segment, self::UNSAFE_KEYS, true)) {
                return $object;
            }
        }

        if ($segments === []) {
            return $object;
        }

        return self::setSegments($object, $segments, 0, $value);
    }

    /**
     * @param array<string|int, mixed> $object
     * @param list<string> $segments
     * @return array<string|int, mixed>
     */
    private static function setSegments(array $object, array $segments, int $index, mixed $value): array
    {
        $key = $segments[$index];
        $isLast = $index === count($segments) - 1;

        if ($isLast) {
            $object[$key] = $value;

            return $object;
        }

        $current = $object[$key] ?? null;
        if (!is_array($current)) {
            $current = preg_match('/^\d+$/', $segments[$index + 1]) === 1 ? [] : [];
        }

        $object[$key] = self::setSegments($current, $segments, $index + 1, $value);

        return $object;
    }

    /**
     * `_.get` with a dot/bracket path. Returns $default when the path is missing.
     *
     * @param string|list<string|int> $path
     */
    public static function get(mixed $object, string|array $path, mixed $default = null): mixed
    {
        $segments = is_string($path) ? self::toPath($path) : $path;
        $current = $object;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (is_object($current) && property_exists($current, (string) $segment)) {
                $current = $current->{$segment};
            } else {
                return $default;
            }
        }

        return $current;
    }

    /**
     * `_.has` with a dot/bracket path.
     *
     * @param string|list<string|int> $path
     */
    public static function has(mixed $object, string|array $path): bool
    {
        $sentinel = new \stdClass();

        return self::get($object, $path, $sentinel) !== $sentinel;
    }

    /**
     * True for an "object-like" associative array (not a list) — the PHP stand-in for `_.isPlainObject`.
     * Empty arrays count as plain objects, as `{}` would.
     */
    public static function isPlainObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** `_.isObject` analogue: arrays and objects. */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) || is_object($value);
    }

    /** `_.isEmpty` for arrays, strings and null. */
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_string($value)) {
            return $value === '';
        }
        if ($value instanceof \Countable) {
            return count($value) === 0;
        }
        if (is_object($value)) {
            return get_object_vars($value) === [];
        }

        return true;
    }

    /**
     * `_.pick`.
     *
     * @param array<string|int, mixed> $object
     * @param list<string|int> $keys
     * @return ($object is array<string, mixed> ? array<string, mixed> : array<string|int, mixed>)
     */
    public static function pick(array $object, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $object)) {
                $out[$key] = $object[$key];
            }
        }

        return $out;
    }

    /**
     * `_.omit`.
     *
     * @param array<string|int, mixed> $object
     * @param list<string|int> $keys
     * @return ($object is array<string, mixed> ? array<string, mixed> : array<string|int, mixed>)
     */
    public static function omit(array $object, array $keys): array
    {
        return array_diff_key($object, array_flip($keys));
    }

    /**
     * `_.merge`: recursive merge where arrays-as-objects merge by key and lists merge by index.
     *
     * @param array<string|int, mixed> $target
     * @param array<string|int, mixed> ...$sources
     * @return ($target is array<string, mixed> ? array<string, mixed> : array<string|int, mixed>)
     */
    public static function merge(array $target, array ...$sources): array
    {
        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if (is_array($value) && isset($target[$key]) && is_array($target[$key])) {
                    $target[$key] = self::merge($target[$key], $value);
                } elseif ($value !== null || !array_key_exists($key, $target)) {
                    $target[$key] = $value;
                }
            }
        }

        return $target;
    }
}
