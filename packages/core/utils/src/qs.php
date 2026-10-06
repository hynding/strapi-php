<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Primitives\Strings;
use Strapi\Utils\Qs\JsArray;

/**
 * Faithful port of the npm `qs` library (6.x) `parse()` and `stringify()`, as Strapi uses them.
 *
 * Strapi's query middleware calls `qs.parse(querystring, { strictNullHandling: true, arrayLimit: 100, depth: 20 })`
 * ({@see self::parseStrapiQuery()}). Compared with PHP's `parse_str`:
 *  - dots in keys are NOT split (`allowDots` is false): `a.b=1` → `['a.b' => '1']`
 *  - spaces and other characters in keys are preserved: `a b=1` → `['a b' => '1']`
 *  - `a=1&a=2` → `['a' => ['1', '2']]`, `a[]=x` → `['a' => ['x']]`, `a[0]=x` → `['a' => ['x']]`
 *  - a key without `=` yields `null` (strictNullHandling) instead of `''`
 *  - at most `parameterLimit` (1000) pairs are read, bracket nesting beyond `depth` is kept as a literal key
 *  - arrays longer than `arrayLimit` become int-keyed maps (upstream: "overflow objects")
 *
 * JavaScript arrays are PHP lists and JavaScript objects are associative arrays. Note that PHP cannot
 * distinguish `{ "0": "a", "1": "b" }` from `["a", "b"]`; upstream only produces the former when
 * `arrayLimit` overflows, which in PHP shows up as a list longer than `arrayLimit`.
 *
 * @phpstan-type ParseOptions array{allowDots?: bool, allowEmptyArrays?: bool, allowPrototypes?: bool, allowSparse?: bool, arrayLimit?: int, comma?: bool, decodeDotInKeys?: bool, decoder?: callable(string, string): ?string, delimiter?: string, depth?: int|false, duplicates?: 'combine'|'first'|'last', ignoreQueryPrefix?: bool, parameterLimit?: int, parseArrays?: bool, strictDepth?: bool, strictMerge?: bool, strictNullHandling?: bool, throwOnLimitExceeded?: bool}
 * @phpstan-type StringifyOptions array{addQueryPrefix?: bool, allowDots?: bool, allowEmptyArrays?: bool, arrayFormat?: 'indices'|'brackets'|'repeat'|'comma', commaRoundTrip?: bool, delimiter?: string, encode?: bool, encodeDotInKeys?: bool, encoder?: callable(string, string): string, encodeValuesOnly?: bool, filter?: callable(string, mixed): mixed|list<string|int>, format?: 'RFC1738'|'RFC3986', indices?: bool, serializeDate?: callable(\DateTimeInterface): string, skipNulls?: bool, sort?: callable(string, string): int, strictNullHandling?: bool}
 */
final class Qs
{
    public const PARSE_DEFAULTS = [
        'allowDots' => false,
        'allowEmptyArrays' => false,
        'allowPrototypes' => false,
        'allowSparse' => false,
        'arrayLimit' => 20,
        'comma' => false,
        'decodeDotInKeys' => false,
        'decoder' => null,
        'delimiter' => '&',
        'depth' => 5,
        'duplicates' => 'combine',
        'ignoreQueryPrefix' => false,
        'parameterLimit' => 1000,
        'parseArrays' => true,
        'strictDepth' => false,
        'strictMerge' => true,
        'strictNullHandling' => false,
        'throwOnLimitExceeded' => false,
    ];

    /** The options Strapi's query middleware passes to `qs.parse`. */
    public const STRAPI_PARSE_OPTIONS = ['strictNullHandling' => true, 'arrayLimit' => 100, 'depth' => 20];

    public const STRINGIFY_DEFAULTS = [
        'addQueryPrefix' => false,
        'allowDots' => false,
        'allowEmptyArrays' => false,
        'arrayFormat' => 'indices',
        'commaRoundTrip' => false,
        'delimiter' => '&',
        'encode' => true,
        'encodeDotInKeys' => false,
        'encoder' => null,
        'encodeValuesOnly' => false,
        'filter' => null,
        'format' => 'RFC3986',
        'serializeDate' => null,
        'skipNulls' => false,
        'sort' => null,
        'strictNullHandling' => false,
    ];

    /** Own enumerable-or-not properties of `Object.prototype`, rejected as keys unless allowPrototypes. */
    private const OBJECT_PROTOTYPE_KEYS = [
        'constructor', '__defineGetter__', '__defineSetter__', 'hasOwnProperty', '__lookupGetter__',
        '__lookupSetter__', 'isPrototypeOf', 'propertyIsEnumerable', 'toString', 'valueOf',
        '__proto__', 'toLocaleString',
    ];

    /**
     * `qs.parse(querystring, { strictNullHandling: true, arrayLimit: 100, depth: 20 })` — what Strapi's
     * query middleware produces for `ctx.query`.
     *
     * @return array<string|int, mixed>
     */
    public static function parseStrapiQuery(string $query): array
    {
        return self::parse($query, self::STRAPI_PARSE_OPTIONS);
    }

    /**
     * @param string|array<string|int, mixed>|null $input  a query string, or an already flat `key => value` map
     * @param ParseOptions $opts
     * @return array<string|int, mixed>
     */
    public static function parse(string|array|null $input, array $opts = []): array
    {
        $options = self::normalizeParseOptions($opts);

        if ($input === '' || $input === null) {
            return [];
        }

        $tempObj = is_string($input) ? self::parseValues($input, $options) : $input;
        $obj = [];

        foreach ($tempObj as $key => $value) {
            $newObj = self::parseKeys((string) $key, $value, $options, is_string($input));
            if ($newObj === null) {
                continue;
            }
            $obj = self::merge($obj, $newObj, $options);
        }

        /** @var array<string|int, mixed> $result */
        $result = self::toPhp($obj, $options['allowSparse']);

        return $result;
    }

    /**
     * @param ParseOptions $opts
     * @return array<string, mixed>
     */
    private static function normalizeParseOptions(array $opts): array
    {
        $options = array_merge(self::PARSE_DEFAULTS, $opts);

        if (!in_array($options['duplicates'], ['combine', 'first', 'last'], true)) {
            throw new \InvalidArgumentException('The duplicates option must be either combine, first, or last');
        }
        if (!array_key_exists('allowDots', $opts) && ($opts['decodeDotInKeys'] ?? false) === true) {
            $options['allowDots'] = true;
        }
        if ($options['depth'] === false) {
            $options['depth'] = 0;
        }

        return $options;
    }

    private static function limitError(int $limit, string $what): \RangeException
    {
        return new \RangeException(sprintf('%s limit exceeded. Only %d %s%s allowed%s.', ucfirst($what), $limit, $what === 'array' ? 'element' : 'parameter', $limit === 1 ? '' : 's', $what === 'array' ? ' in an array' : ''));
    }

    /** Default decoder: `+` → space then percent-decoding. */
    public static function decode(string $str): string
    {
        return rawurldecode(str_replace('+', ' ', $str));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed> key → string|null|JsArray
     */
    private static function parseValues(string $str, array $options): array
    {
        $obj = [];

        $cleanStr = $options['ignoreQueryPrefix'] ? (string) preg_replace('/^\?/', '', $str) : $str;
        $cleanStr = str_ireplace(['%5B', '%5D'], ['[', ']'], $cleanStr);

        $limit = $options['parameterLimit'];
        $parts = self::splitWithLimit($cleanStr, (string) $options['delimiter'], $options['throwOnLimitExceeded'] ? $limit + 1 : $limit);

        if ($options['throwOnLimitExceeded'] && count($parts) > $limit) {
            throw self::limitError($limit, 'parameter');
        }

        $decoder = $options['decoder'];
        $decode = static fn (string $value, string $kind): ?string => $decoder !== null ? $decoder($value, $kind) : self::decode($value);

        foreach ($parts as $part) {
            $bracketEqualsPos = strpos($part, ']=');
            $pos = $bracketEqualsPos === false ? strpos($part, '=') : $bracketEqualsPos + 1;

            if ($pos === false) {
                $key = $decode($part, 'key');
                $val = $options['strictNullHandling'] ? null : '';
            } else {
                $key = $decode(substr($part, 0, $pos), 'key');
                $val = null;
                if ($key !== null) {
                    $existingLength = isset($obj[$key]) && $obj[$key] instanceof JsArray ? $obj[$key]->length() : 0;
                    $raw = self::parseArrayValue(substr($part, $pos + 1), $options, $existingLength, !str_contains($part, '[]='));
                    $val = is_array($raw)
                        ? new JsArray(array_map(static fn (string $v): ?string => $decode($v, 'value'), $raw))
                        : $decode($raw, 'value');
                }
            }

            if (str_contains($part, '[]=') && $val instanceof JsArray) {
                $val = new JsArray([$val]);
            }

            if ($options['comma'] && $val instanceof JsArray && $val->length() > $options['arrayLimit']) {
                $val = self::combine(new JsArray(), $val, $options);
            }

            if ($key === null) {
                continue;
            }

            $existing = array_key_exists($key, $obj);
            if ($existing && ($options['duplicates'] === 'combine' || str_contains($part, '[]='))) {
                $obj[$key] = self::combine($obj[$key], $val, $options);
            } elseif (!$existing || $options['duplicates'] === 'last') {
                $obj[$key] = $val;
            }
        }

        return $obj;
    }

    /**
     * JavaScript `String.prototype.split(sep, limit)`: at most $limit parts, the remainder dropped.
     *
     * @return list<string>
     */
    private static function splitWithLimit(string $str, string $delimiter, int $limit): array
    {
        $parts = explode($delimiter, $str);

        return array_slice($parts, 0, $limit);
    }

    /**
     * @param array<string, mixed> $options
     * @return string|list<string>
     */
    private static function parseArrayValue(string $val, array $options, int $currentArrayLength, bool $isFlatArrayValue): string|array
    {
        if ($val !== '' && $options['comma'] && str_contains($val, ',')) {
            $split = explode(',', $val);
            if ($isFlatArrayValue && $options['throwOnLimitExceeded'] && count($split) - 1 >= $options['arrayLimit']) {
                throw self::limitError($options['arrayLimit'], 'array');
            }

            return $split;
        }

        if ($options['throwOnLimitExceeded'] && $currentArrayLength >= $options['arrayLimit']) {
            throw self::limitError($options['arrayLimit'], 'array');
        }

        return $val;
    }

    /**
     * `utils.combine(a, b)`: concat into a JS array, overflowing to an int-keyed object past arrayLimit.
     *
     * @param array<string, mixed> $options
     */
    private static function combine(mixed $a, mixed $b, array $options): JsArray
    {
        if ($a instanceof JsArray && $a->overflow) {
            if ($options['throwOnLimitExceeded']) {
                throw self::limitError($options['arrayLimit'], 'array');
            }
            $a->items[$a->maxIndex + 1] = $b;
            $a->maxIndex++;

            return $a;
        }

        // `[].concat(a, b)`: arrays are spread (holes included, re-indexed densely), scalars appended
        $result = new JsArray();
        foreach ([$a, $b] as $part) {
            if ($part instanceof JsArray) {
                $base = $result->length();
                foreach ($part->items as $index => $item) {
                    $result->items[$base + $index] = $item;
                }
                if ($part->items === []) {
                    continue;
                }
            } else {
                $result->push($part);
            }
        }

        if ($result->length() > $options['arrayLimit']) {
            if ($options['throwOnLimitExceeded']) {
                throw self::limitError($options['arrayLimit'], 'array');
            }

            return self::markOverflow($result, $result->length() - 1);
        }

        return $result;
    }

    private static function markOverflow(JsArray $array, int $maxIndex): JsArray
    {
        $array->overflow = true;
        $array->maxIndex = $maxIndex;

        return $array;
    }

    private static function isOverflow(mixed $value): bool
    {
        return $value instanceof JsArray && $value->overflow;
    }

    /**
     * @param list<string> $chain
     * @param array<string, mixed> $options
     */
    private static function parseObject(array $chain, mixed $val, array $options, bool $valuesParsed): mixed
    {
        // upstream computes a `currentArrayLength` from `val[parentKey]` here, which is never set for the
        // values parseValues() produces; the throwOnLimitExceeded check it feeds is covered by combine()
        $leaf = $valuesParsed ? $val : (is_string($val) ? self::parseArrayValue($val, $options, 0, true) : $val);
        if (is_array($leaf) && !$valuesParsed) {
            $leaf = new JsArray($leaf);
        }

        for ($i = count($chain) - 1; $i >= 0; --$i) {
            $root = $chain[$i];

            if ($root === '[]' && $options['parseArrays']) {
                if (self::isOverflow($leaf)) {
                    $obj = $leaf;
                } elseif ($options['allowEmptyArrays'] && ($leaf === '' || ($options['strictNullHandling'] && $leaf === null))) {
                    $obj = new JsArray();
                } else {
                    $obj = self::combine(new JsArray(), $leaf, $options);
                }
            } else {
                $obj = [];
                $cleanRoot = str_starts_with($root, '[') && str_ends_with($root, ']') ? substr($root, 1, -1) : $root;
                $decodedRoot = $options['decodeDotInKeys'] ? str_replace('%2E', '.', $cleanRoot) : $cleanRoot;
                $isValidArrayIndex = preg_match('/^\d+$/', $decodedRoot) === 1
                    && $root !== $decodedRoot
                    && (string) (int) $decodedRoot === $decodedRoot
                    && $options['parseArrays'];
                $index = $isValidArrayIndex ? (int) $decodedRoot : -1;

                if (!$options['parseArrays'] && $decodedRoot === '') {
                    $obj = [0 => $leaf];
                } elseif ($isValidArrayIndex && $index < $options['arrayLimit']) {
                    $obj = new JsArray([$index => $leaf]);
                } elseif ($isValidArrayIndex && $options['throwOnLimitExceeded']) {
                    throw self::limitError($options['arrayLimit'], 'array');
                } elseif ($isValidArrayIndex) {
                    $obj = self::markOverflow(new JsArray([$index => $leaf]), $index);
                } elseif ($decodedRoot !== '__proto__') {
                    $obj[$decodedRoot] = $leaf;
                }
            }

            $leaf = $obj;
        }

        return $leaf;
    }

    /**
     * Split a key like `a[b][c[]]` into `['a', '[b]', '[c[]]']` honouring depth and prototype guards.
     *
     * @param array<string, mixed> $options
     * @return list<string>|null null when the key must be ignored (prototype key)
     */
    private static function splitKeyIntoSegments(string $originalKey, array $options): ?array
    {
        $key = $options['allowDots'] ? (string) preg_replace('/\.([^.[]+)/', '[$1]', $originalKey) : $originalKey;

        $isProto = static fn (string $k): bool => in_array($k, self::OBJECT_PROTOTYPE_KEYS, true);

        if ($options['depth'] <= 0) {
            if ($isProto($key) && !$options['allowPrototypes']) {
                return null;
            }

            return [$key];
        }

        $segments = [];

        $first = strpos($key, '[');
        $parent = $first !== false ? substr($key, 0, $first) : $key;
        if ($parent !== '') {
            if ($isProto($parent) && !$options['allowPrototypes']) {
                return null;
            }
            $segments[] = $parent;
        }

        $n = strlen($key);
        $open = $first === false ? -1 : $first;
        $collected = 0;

        while ($open >= 0 && $collected < $options['depth']) {
            $level = 1;
            $i = $open + 1;
            $close = -1;

            while ($i < $n && $close < 0) {
                $ch = $key[$i];
                if ($ch === '[') {
                    $level++;
                } elseif ($ch === ']') {
                    $level--;
                    if ($level === 0) {
                        $close = $i;
                    }
                }
                $i++;
            }

            if ($close < 0) {
                $segments[] = '[' . substr($key, $open) . ']';

                return $segments;
            }

            $seg = substr($key, $open, $close - $open + 1);
            $content = substr($seg, 1, -1);
            if ($isProto($content) && !$options['allowPrototypes']) {
                return null;
            }

            $segments[] = $seg;
            $collected++;

            $next = strpos($key, '[', $close + 1);
            $open = $next === false ? -1 : $next;
        }

        if ($open >= 0) {
            if ($options['strictDepth']) {
                throw new \RangeException('Input depth exceeded depth option of ' . $options['depth'] . ' and strictDepth is true');
            }
            $segments[] = '[' . substr($key, $open) . ']';
        }

        return $segments;
    }

    /** @param array<string, mixed> $options */
    private static function parseKeys(string $givenKey, mixed $val, array $options, bool $valuesParsed): mixed
    {
        if ($givenKey === '') {
            return null;
        }

        $keys = self::splitKeyIntoSegments($givenKey, $options);
        if ($keys === null) {
            return null;
        }

        return self::parseObject($keys, $val, $options, $valuesParsed);
    }

    private static function isObjectLike(mixed $value): bool
    {
        return is_array($value) || $value instanceof JsArray;
    }

    /**
     * `utils.merge(target, source, options)` over the internal node model
     * (null | string | JsArray | array as object).
     *
     * @param array<string, mixed> $options
     */
    private static function merge(mixed $target, mixed $source, array $options): mixed
    {
        if ($source === null || $source === '') {
            return $target;
        }

        if (!self::isObjectLike($source)) {
            if ($target instanceof JsArray && !$target->overflow) {
                $nextIndex = $target->length();
                if ($nextIndex >= $options['arrayLimit']) {
                    if ($options['throwOnLimitExceeded']) {
                        throw self::limitError($options['arrayLimit'], 'array');
                    }
                    $target->push($source);

                    return self::markOverflow($target, $nextIndex);
                }
                $target->items[$nextIndex] = $source;
            } elseif ($target instanceof JsArray) {
                $target->items[$target->maxIndex + 1] = $source;
                $target->maxIndex++;
            } elseif (is_array($target)) {
                if ($options['strictMerge']) {
                    return new JsArray([$target, $source]);
                }
                if ($options['allowPrototypes'] || !in_array((string) $source, self::OBJECT_PROTOTYPE_KEYS, true)) {
                    $target[(string) $source] = true;
                }
            } else {
                return new JsArray([$target, $source]);
            }

            return $target;
        }

        if (!self::isObjectLike($target)) {
            if (self::isOverflow($source)) {
                /** @var JsArray $source */
                $result = new JsArray([0 => $target]);
                foreach ($source->items as $oldKey => $item) {
                    $result->items[$oldKey + 1] = $item;
                }

                return self::markOverflow($result, $source->maxIndex + 1);
            }

            $combined = new JsArray([0 => $target]);
            if ($source instanceof JsArray) {
                foreach ($source->items as $index => $item) {
                    $combined->items[$index + 1] = $item;
                }
            } else {
                $combined->items[1] = $source;
            }

            if ($combined->length() > $options['arrayLimit']) {
                if ($options['throwOnLimitExceeded']) {
                    throw self::limitError($options['arrayLimit'], 'array');
                }

                return self::markOverflow($combined, $combined->length() - 1);
            }

            return $combined;
        }

        $mergeTarget = $target;
        if ($target instanceof JsArray && !$target->overflow && !($source instanceof JsArray && !$source->overflow)) {
            $mergeTarget = $target->toObject();
            $targetWasOverflow = false;
        } else {
            $targetWasOverflow = self::isOverflow($target);
        }

        if ($target instanceof JsArray && !$target->overflow && $source instanceof JsArray && !$source->overflow) {
            foreach ($source->items as $i => $item) {
                if ($target->has($i)) {
                    $targetItem = $target->items[$i];
                    if ($targetItem !== null && self::isObjectLike($targetItem) && $item !== null && self::isObjectLike($item)) {
                        $target->items[$i] = self::merge($targetItem, $item, $options);
                    } else {
                        $target->push($item);
                    }
                } else {
                    $target->items[$i] = $item;
                }
            }
            if ($target->length() > $options['arrayLimit']) {
                if ($options['throwOnLimitExceeded']) {
                    throw self::limitError($options['arrayLimit'], 'array');
                }

                return self::markOverflow($target, $target->length() - 1);
            }

            return $target;
        }

        // Object merge. Overflow JsArrays are treated as int-keyed objects here.
        $sourceEntries = $source instanceof JsArray ? $source->items : $source;
        $sourceIsOverflow = self::isOverflow($source);

        if ($mergeTarget instanceof JsArray) {
            // overflow target: merge keys into its item map
            foreach ($sourceEntries as $key => $value) {
                if (array_key_exists($key, $mergeTarget->items)) {
                    $mergeTarget->items[$key] = self::merge($mergeTarget->items[$key], $value, $options);
                } else {
                    $mergeTarget->items[$key] = $value;
                }
                if (is_int($key) && $key > $mergeTarget->maxIndex) {
                    $mergeTarget->maxIndex = $key;
                }
            }

            return $mergeTarget;
        }

        /** @var array<string|int, mixed> $acc */
        $acc = $mergeTarget;
        $accOverflow = null;
        foreach ($sourceEntries as $key => $value) {
            if (array_key_exists($key, $acc)) {
                $acc[$key] = self::merge($acc[$key], $value, $options);
            } else {
                $acc[$key] = $value;
            }

            if ($sourceIsOverflow && $accOverflow === null) {
                /** @var JsArray $source */
                $accOverflow = $source->maxIndex;
            }
            if ($accOverflow !== null && is_int($key) && $key >= 0 && $key > $accOverflow) {
                $accOverflow = $key;
            }
        }

        if ($accOverflow !== null || $targetWasOverflow) {
            $overflow = new JsArray();
            foreach ($acc as $key => $value) {
                $overflow->items[(int) $key] = $value;
            }

            return self::markOverflow($overflow, $accOverflow ?? $overflow->length() - 1);
        }

        return $acc;
    }

    /**
     * Convert the internal node tree into plain PHP values, compacting sparse arrays into lists.
     */
    private static function toPhp(mixed $value, bool $allowSparse): mixed
    {
        if ($value instanceof JsArray) {
            if ($value->overflow) {
                $out = [];
                foreach ($value->toObject() as $key => $item) {
                    $out[$key] = self::toPhp($item, $allowSparse);
                }

                return $out;
            }

            $items = $value->toObject();
            if ($allowSparse) {
                return array_map(static fn (mixed $item): mixed => self::toPhp($item, $allowSparse), $items);
            }

            $out = [];
            foreach ($items as $item) {
                $out[] = self::toPhp($item, $allowSparse);
            }

            return $out;
        }

        if (is_array($value)) {
            $out = [];
            foreach (self::jsKeyOrder($value) as $key) {
                $out[$key] = self::toPhp($value[$key], $allowSparse);
            }

            return $out;
        }

        return $value;
    }

    /**
     * JavaScript object key order: integer-like keys ascending first, then the rest in insertion order.
     *
     * @param array<string|int, mixed> $object
     * @return list<string|int>
     */
    private static function jsKeyOrder(array $object): array
    {
        $ints = [];
        $others = [];
        foreach (array_keys($object) as $key) {
            if (is_int($key) && $key >= 0) {
                $ints[] = $key;
            } else {
                $others[] = $key;
            }
        }
        sort($ints);

        return [...$ints, ...$others];
    }

    // ---------------------------------------------------------------------------------------------
    // stringify
    // ---------------------------------------------------------------------------------------------

    /**
     * `qs.stringify(object, options)`.
     *
     * Lists serialize as JS arrays (`a[0]=x&a[1]=y` by default), associative arrays and objects as nested
     * keys (`a[b]=x`), `DateTimeInterface` as ISO 8601, booleans as `true`/`false`, null as `a=` (or `a`
     * with strictNullHandling).
     *
     * @param array<string|int, mixed>|object|null $object
     * @param StringifyOptions $opts
     */
    public static function stringify(array|object|null $object, array $opts = []): string
    {
        $options = self::normalizeStringifyOptions($opts);
        $obj = $object;

        $objKeys = null;
        $filter = $options['filter'];
        if (is_callable($filter)) {
            $obj = $filter('', $obj);
        } elseif (is_array($filter)) {
            $objKeys = $filter;
        }

        if ($obj === null || (!is_array($obj) && !is_object($obj))) {
            return '';
        }

        $obj = self::toArrayValue($obj);

        if ($objKeys === null) {
            $objKeys = array_keys($obj);
        }

        if ($options['sort'] !== null) {
            usort($objKeys, static fn (mixed $a, mixed $b): int => $options['sort']((string) $a, (string) $b));
        }

        $keys = [];
        foreach ($objKeys as $key) {
            if (!array_key_exists($key, $obj)) {
                continue;
            }
            $value = $obj[$key];
            if ($options['skipNulls'] && $value === null) {
                continue;
            }
            foreach (self::stringifyValue($value, (string) $key, $options) as $pair) {
                $keys[] = $pair;
            }
        }

        $joined = implode((string) $options['delimiter'], $keys);
        $prefix = $options['addQueryPrefix'] ? '?' : '';

        return $joined !== '' ? $prefix . $joined : '';
    }

    /**
     * @param StringifyOptions $opts
     * @return array<string, mixed>
     */
    private static function normalizeStringifyOptions(array $opts): array
    {
        $options = array_merge(self::STRINGIFY_DEFAULTS, $opts);

        if (!in_array($options['format'], ['RFC1738', 'RFC3986'], true)) {
            throw new \InvalidArgumentException('Unknown format option provided.');
        }

        if (!array_key_exists('arrayFormat', $opts) && array_key_exists('indices', $opts)) {
            $options['arrayFormat'] = $opts['indices'] ? 'indices' : 'repeat';
        }
        if (!in_array($options['arrayFormat'], ['indices', 'brackets', 'repeat', 'comma'], true)) {
            $options['arrayFormat'] = 'indices';
        }
        if (!array_key_exists('allowDots', $opts) && ($opts['encodeDotInKeys'] ?? false) === true) {
            $options['allowDots'] = true;
        }

        return $options;
    }

    /**
     * Objects become associative arrays (JsonSerializable first), leaving arrays alone.
     *
     * @return array<string|int, mixed>
     */
    private static function toArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $serialized : ['' => $serialized];
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }

    /** RFC3986 percent-encoding (RFC1738 additionally leaves `(` and `)` alone). */
    public static function encode(string $str, string $format = 'RFC3986'): string
    {
        $encoded = rawurlencode($str);

        return $format === 'RFC1738' ? str_replace(['%28', '%29'], ['(', ')'], $encoded) : $encoded;
    }

    private static function formatter(string $value, string $format): string
    {
        return $format === 'RFC1738' ? str_replace('%20', '+', $value) : $value;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function stringifyValue(mixed $value, string $prefix, array $options): array
    {
        $obj = $value;
        $format = (string) $options['format'];
        $encoder = $options['encode'] ? ($options['encoder'] ?? static fn (string $s, string $kind): string => self::encode($s, $format)) : null;
        $encodeValuesOnly = (bool) $options['encodeValuesOnly'];
        $serializeDate = $options['serializeDate'] ?? static fn (\DateTimeInterface $d): string => \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $arrayFormat = (string) $options['arrayFormat'];

        $filter = $options['filter'];
        if (is_callable($filter)) {
            $obj = $filter($prefix, $obj);
            if ($obj === null) {
                // PHP has no `undefined`: a filter returning null drops the key, as returning undefined does in JS.
                return [];
            }
        } elseif ($obj instanceof \DateTimeInterface) {
            $obj = $serializeDate($obj);
        } elseif ($arrayFormat === 'comma' && is_array($obj) && array_is_list($obj)) {
            $obj = array_map(static fn (mixed $v): mixed => $v instanceof \DateTimeInterface ? $serializeDate($v) : $v, $obj);
        }

        if ($obj === null) {
            if ($options['strictNullHandling']) {
                return [self::formatter($encoder !== null && !$encodeValuesOnly ? $encoder($prefix, 'key') : $prefix, $format)];
            }
            $obj = '';
        }

        if (is_scalar($obj) || $obj instanceof \Stringable) {
            $stringValue = is_object($obj) ? (string) $obj : Strings::stringify($obj);
            if ($encoder !== null) {
                $keyValue = $encodeValuesOnly ? $prefix : $encoder($prefix, 'key');

                return [self::formatter($keyValue, $format) . '=' . self::formatter($encoder($stringValue, 'value'), $format)];
            }

            return [self::formatter($prefix, $format) . '=' . self::formatter($stringValue, $format)];
        }

        if (!is_array($obj) && !is_object($obj)) {
            return [];
        }

        $obj = self::toArrayValue($obj);
        $isList = array_is_list($obj) && $obj !== [];
        $isArrayLike = $isList || $obj === [];

        $values = [];

        if ($arrayFormat === 'comma' && $isArrayLike) {
            if ($encodeValuesOnly && $encoder !== null) {
                $obj = array_map(static fn (mixed $v): mixed => $v === null ? $v : $encoder(Strings::stringify($v), 'value'), $obj);
            }
            $joined = $obj !== [] ? implode(',', array_map(static fn (mixed $v): string => $v === null ? '' : Strings::stringify($v), $obj)) : null;
            $objKeys = [['value' => $joined !== '' ? $joined : null]];
        } elseif (is_array($filter)) {
            $objKeys = $filter;
        } else {
            $objKeys = array_keys($obj);
            if ($options['sort'] !== null) {
                usort($objKeys, static fn (mixed $a, mixed $b): int => $options['sort']((string) $a, (string) $b));
            }
        }

        $encodedPrefix = $options['encodeDotInKeys'] ? str_replace('.', '%2E', $prefix) : $prefix;
        $adjustedPrefix = $options['commaRoundTrip'] && $isArrayLike && count($obj) === 1 ? $encodedPrefix . '[]' : $encodedPrefix;

        if ($options['allowEmptyArrays'] && $isArrayLike && $obj === []) {
            return [$adjustedPrefix . '[]'];
        }

        foreach ($objKeys as $key) {
            if (is_array($key) && array_key_exists('value', $key)) {
                $itemValue = $key['value'];
                if ($itemValue === null && $arrayFormat === 'comma') {
                    // `obj.length > 0 ? obj.join(',') || null : undefined` — undefined is skipped, null is kept
                    if ($obj === []) {
                        continue;
                    }
                }
                $keyName = '';
            } else {
                if (!array_key_exists($key, $obj)) {
                    continue;
                }
                $itemValue = $obj[$key];
                $keyName = (string) $key;
            }

            if ($options['skipNulls'] && $itemValue === null) {
                continue;
            }

            $encodedKey = $options['allowDots'] && $options['encodeDotInKeys'] ? str_replace('.', '%2E', $keyName) : $keyName;
            if ($isArrayLike) {
                $keyPrefix = match ($arrayFormat) {
                    'brackets' => $adjustedPrefix . '[]',
                    'repeat', 'comma' => $adjustedPrefix,
                    default => $adjustedPrefix . '[' . $encodedKey . ']',
                };
            } else {
                $keyPrefix = $adjustedPrefix . ($options['allowDots'] ? '.' . $encodedKey : '[' . $encodedKey . ']');
            }

            $childOptions = $options;
            if ($arrayFormat === 'comma' && $encodeValuesOnly && $isArrayLike) {
                $childOptions['encode'] = false;
            }

            foreach (self::stringifyValue($itemValue, $keyPrefix, $childOptions) as $pair) {
                $values[] = $pair;
            }
        }

        return $values;
    }
}
