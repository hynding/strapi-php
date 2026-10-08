<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/json.ts: `diff(a, b)` computes the differences between two JSON values.
 *
 * PHP arrays stand for both JS arrays and objects: two arrays are compared key by key (the union
 * of their keys, `a`'s first), which is what upstream does for objects and, through `zip`, for
 * arrays. A key missing on one side is JS `undefined` ({@see self::UNDEFINED}); `null` is a value
 * (`typeof null === 'object'`).
 *
 * A diff is `['kind' => 'added'|'deleted', 'path' => list<string>, 'type' => string, 'value' => mixed]`
 * or `['kind' => 'modified', 'path' => list<string>, 'types' => [string, string], 'values' => [mixed, mixed]]`.
 *
 * @phpstan-type Diff array{kind: string, path: list<string>, type?: string, value?: mixed, types?: array{string, string}, values?: array{mixed, mixed}}
 */
final class Json
{
    /** Marker for a missing value (JS `undefined`). */
    public const string UNDEFINED = "\0__undefined__\0";

    /**
     * @param array{path: list<string>} $ctx
     *
     * @return list<Diff>
     */
    public static function diff(mixed $a, mixed $b, array $ctx = ['path' => []]): array
    {
        $diffs = [];
        $path = $ctx['path'];

        $aType = self::typeOf($a);
        $bType = self::typeOf($b);

        if (is_array($a) && is_array($b)) {
            $keys = array_keys($a);
            foreach (array_keys($b) as $key) {
                if (!array_key_exists($key, $a)) {
                    $keys[] = $key;
                }
            }

            foreach ($keys as $key) {
                $aValue = array_key_exists($key, $a) ? $a[$key] : self::UNDEFINED;
                $bValue = array_key_exists($key, $b) ? $b[$key] : self::UNDEFINED;

                foreach (self::diff($aValue, $bValue, ['path' => [...$path, (string) $key]]) as $nested) {
                    $diffs[] = $nested;
                }
            }

            return $diffs;
        }

        if (!self::isEqual($a, $b)) {
            if ($aType === 'undefined') {
                return [['kind' => 'added', 'path' => $path, 'type' => $bType, 'value' => $b]];
            }

            if ($bType === 'undefined') {
                return [['kind' => 'deleted', 'path' => $path, 'type' => $aType, 'value' => $a]];
            }

            return [['kind' => 'modified', 'path' => $path, 'types' => [$aType, $bType], 'values' => [$a, $b]]];
        }

        return $diffs;
    }

    /** JS `typeof` of a decoded JSON value. */
    public static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === self::UNDEFINED => 'undefined',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => 'object',
        };
    }

    /** lodash `isEqual` for JSON values (numbers compare by value: `1` equals `1.0`). */
    public static function isEqual(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::isEqual($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /**
     * The value `JSON.stringify` serializes: functions (closures) are dropped from objects and
     * become `null` in arrays, dates become ISO strings, other objects their JSON form.
     */
    public static function toJSONValue(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
        }
        if ($value instanceof \JsonSerializable) {
            return self::toJSONValue($value->jsonSerialize());
        }
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            if ($item instanceof \Closure && !$isList) {
                continue; // a function-valued property is omitted
            }
            $out[$key] = self::toJSONValue($item);
        }

        return $out;
    }

    /**
     * `JSON.stringify(value)`: slashes and unicode unescaped, as JS does.
     */
    public static function stringify(mixed $value, bool $pretty = false): string
    {
        $json = json_encode(self::toJSONValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));

        if ($pretty) {
            // JSON.stringify(value, null, 2): two-space indentation
            $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);
        }

        return $json;
    }
}
