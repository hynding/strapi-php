<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

use Strapi\Utils\Zod\Locales\En;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * Helpers from zod's `core/util.ts`, plus the JavaScript semantics zod relies on that PHP lacks:
 * `typeof`-style type names, `String(x)`, `Number(x)`, truthiness, UTF-16 string length, and
 * the object/array split of PHP arrays (a list, including `[]`, is a JS array; any other array or
 * a `stdClass` is a JS object).
 *
 * @phpstan-type NormalizedParams array{error: \Closure|null, abort: bool|null, path: list<string|int>|null, params: array<string, mixed>|null, when: \Closure|null}
 */
final class Util
{
    /**
     * zod's `normalizeParams`: a string becomes the error message; an array may carry
     * `message` or `error` (a string or `fn(array $issue): string|array{message: string}|null`),
     * `abort`, `path`, `params` and `when`.
     *
     * @param string|array<string, mixed>|null $params
     *
     * @return NormalizedParams
     */
    public static function normalizeParams(string|array|null $params): array
    {
        $normalized = ['error' => null, 'abort' => null, 'path' => null, 'params' => null, 'when' => null];
        if ($params === null) {
            return $normalized;
        }
        if (is_string($params)) {
            $normalized['error'] = static fn (): string => $params;

            return $normalized;
        }
        if (array_key_exists('message', $params) && $params['message'] !== null) {
            if (array_key_exists('error', $params) && $params['error'] !== null) {
                throw new \InvalidArgumentException('Cannot specify both `message` and `error` params');
            }
            $params['error'] = $params['message'];
        }
        $error = $params['error'] ?? null;
        if (is_string($error)) {
            $normalized['error'] = static fn (): string => $error;
        } elseif ($error instanceof \Closure) {
            $normalized['error'] = $error;
        }
        if (isset($params['abort'])) {
            $normalized['abort'] = (bool) $params['abort'];
        }
        if (isset($params['path']) && is_array($params['path'])) {
            /** @var list<string|int> $path */
            $path = array_values($params['path']);
            $normalized['path'] = $path;
        }
        if (isset($params['params']) && is_array($params['params'])) {
            /** @var array<string, mixed> $p */
            $p = $params['params'];
            $normalized['params'] = $p;
        }
        if (isset($params['when']) && $params['when'] instanceof \Closure) {
            $normalized['when'] = $params['when'];
        }

        return $normalized;
    }

    /**
     * zod's `finalizeIssue`: resolve the message (explicit message, then the `error` param of
     * the schema/check that raised it, then the English locale) and drop internal keys.
     *
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>
     */
    public static function finalizeIssue(array $issue): array
    {
        $message = $issue['message'] ?? null;
        if (!is_string($message) || $message === '') {
            $message = null;
            $inst = $issue['inst'] ?? null;
            $errorMap = ($inst instanceof ZodType || $inst instanceof ZodCheck) ? $inst->errorMap() : null;
            if ($errorMap !== null) {
                $message = self::unwrapMessage($errorMap(self::publicIssue($issue)));
            }
            $message ??= En::message($issue);
        }
        $rest = $issue;
        unset($rest['inst'], $rest['continue'], $rest['input']);
        if (!array_key_exists('path', $rest)) {
            $rest['path'] = [];
        }
        $rest['message'] = $message;

        return $rest;
    }

    /**
     * The issue as an `error` callback sees it: zod passes the raw issue (with `input`), so
     * callbacks may test `$issue['input'] === Undefined::Value`.
     *
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>
     */
    private static function publicIssue(array $issue): array
    {
        unset($issue['inst'], $issue['continue']);
        if (!array_key_exists('input', $issue)) {
            $issue['input'] = Undefined::Value;
        }

        return $issue;
    }

    public static function unwrapMessage(mixed $message): ?string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_array($message) && isset($message['message']) && is_string($message['message'])) {
            return $message['message'];
        }

        return null;
    }

    /**
     * Prefix every issue's path with `$key` (zod's `prefixIssues`).
     *
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    public static function prefixIssues(string|int $key, array $issues): array
    {
        foreach ($issues as $i => $issue) {
            /** @var list<string|int> $path */
            $path = $issue['path'] ?? [];
            array_unshift($path, $key);
            $issue['path'] = $path;
            $issues[$i] = $issue;
        }

        return $issues;
    }

    /** zod's `util.parsedType`, the "received ..." part of invalid_type messages. */
    public static function parsedType(mixed $data): string
    {
        return match (true) {
            $data === Undefined::Value => 'undefined',
            $data === null => 'null',
            is_bool($data) => 'boolean',
            is_int($data) => 'number',
            is_float($data) => is_nan($data) ? 'nan' : 'number',
            is_string($data) => 'string',
            is_array($data) => array_is_list($data) ? 'array' : 'object',
            $data instanceof \Closure => 'function',
            $data instanceof \stdClass => 'object',
            $data instanceof \DateTimeInterface => 'Date',
            is_object($data) => (new \ReflectionClass($data))->getShortName(),
            default => 'unknown',
        };
    }

    /** zod's `util.stringifyPrimitive`. */
    public static function stringifyPrimitive(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        return self::jsString($value);
    }

    /** @param list<mixed> $values */
    public static function joinValues(array $values, string $separator = '|'): string
    {
        return implode($separator, array_map(self::stringifyPrimitive(...), $values));
    }

    /** JavaScript `String(value)`. */
    public static function jsString(mixed $value): string
    {
        return match (true) {
            $value === Undefined::Value => 'undefined',
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => self::numberToString($value),
            is_string($value) => $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(
                static fn (mixed $v): string => $v === null || $v === Undefined::Value ? '' : self::jsString($v),
                $value,
            )),
            $value instanceof \Stringable => (string) $value,
            default => '[object Object]',
        };
    }

    /** JavaScript `Number.prototype.toString()`. */
    public static function numberToString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if ($value == 0.0) {
            return '0';
        }
        // Shortest round-trip digits, then JavaScript's choice between plain and exponent form.
        $repr = (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        $negative = str_starts_with($repr, '-');
        $repr = ltrim($repr, '-');
        $exponent = 0;
        if (preg_match('/^([0-9.]+)[eE]([+-]?\d+)$/', $repr, $m) === 1) {
            $repr = $m[1];
            $exponent = (int) $m[2];
        }
        [$int, $frac] = array_pad(explode('.', $repr, 2), 2, '');
        $digits = ltrim($int . $frac, '0');
        $leadingZeros = strlen($int . $frac) - strlen(ltrim($int . $frac, '0'));
        $point = strlen($int) + $exponent - $leadingZeros; // position of the decimal point within $digits
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            return '0';
        }
        $k = strlen($digits);
        $n = $point;
        if ($k <= $n && $n <= 21) {
            $out = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $out = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $out = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $e = $n - 1;
            $sign = $e < 0 ? '-' : '+';
            $out = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . $sign . abs($e);
        }

        return ($negative ? '-' : '') . $out;
    }

    /** JavaScript `Number(value)`. */
    public static function jsNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if ($value === null) {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            $s = trim($value, " \t\n\r\v\f\u{00A0}\u{FEFF}");
            if ($s === '') {
                return 0;
            }
            if (preg_match('/^[+-]?Infinity$/', $s) === 1) {
                return str_starts_with($s, '-') ? -INF : INF;
            }
            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $s) === 1) {
                return hexdec(substr($s, 2));
            }
            if (preg_match('/^0[oO][0-7]+$/', $s) === 1) {
                return octdec(substr($s, 2));
            }
            if (preg_match('/^0[bB][01]+$/', $s) === 1) {
                return bindec(substr($s, 2));
            }
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $s) === 1) {
                $f = (float) $s;
                if (floor($f) === $f && abs($f) <= 9007199254740991) {
                    return (int) $f; // JavaScript has one number type; integral values read best as ints
                }

                return $f;
            }

            return NAN;
        }
        if (is_array($value) && array_is_list($value)) {
            return match (count($value)) {
                0 => 0,
                1 => self::jsNumber(self::jsString($value)),
                default => NAN,
            };
        }

        return NAN;
    }

    /** JavaScript truthiness (`Boolean(value)`). */
    public static function jsTruthy(mixed $value): bool
    {
        return match (true) {
            $value === Undefined::Value, $value === null, $value === false, $value === '' => false,
            is_int($value) => $value !== 0,
            is_float($value) => !is_nan($value) && $value != 0.0,
            default => true,
        };
    }

    /** A JS array: a PHP list, including `[]`. */
    public static function isArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * A JS object (zod's `util.isObject`): a non-list array, a `stdClass` or another object.
     * `[]` counts too when `$emptyArrayIsObject` (z.object() and z.record() accept it as `{}`).
     */
    public static function isObject(mixed $value, bool $emptyArrayIsObject = true): bool
    {
        if (is_array($value)) {
            return !array_is_list($value) || ($emptyArrayIsObject && $value === []);
        }

        return is_object($value) && !$value instanceof \Closure && !$value instanceof Undefined;
    }

    /**
     * The own enumerable properties of a JS object, keys as strings.
     *
     * @return array<string, mixed> (PHP turns numeric-string keys back into ints; read keys as strings)
     */
    public static function entries(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }

    /** `value.length` for strings (UTF-16 code units, as in JavaScript) and JS arrays; null otherwise. */
    public static function length(mixed $value): ?int
    {
        if (is_string($value)) {
            return self::utf16Length($value);
        }
        if (is_array($value) && array_is_list($value)) {
            return count($value);
        }

        return null;
    }

    public static function utf16Length(string $value): int
    {
        if (preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return strlen($value);
        }
        $converted = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');

        return intdiv(strlen($converted), 2);
    }

    /** `===` with JavaScript's single number type (1 and 1.0 are the same value). */
    public static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    /** @param list<mixed> $values */
    public static function contains(array $values, mixed $value): bool
    {
        foreach ($values as $candidate) {
            if (self::same($candidate, $value)) {
                return true;
            }
        }

        return false;
    }

    /** Output form of a value: `undefined` becomes `null`. */
    public static function output(mixed $value): mixed
    {
        return $value === Undefined::Value ? null : $value;
    }
}
