<?php

declare(strict_types=1);

namespace Strapi\Utils\Primitives;

/** Port of packages/core/utils/src/primitives/strings.ts (slugify replaced by a small transliterator). */
final class Strings
{
    /** Replacements @sindresorhus/slugify applies before transliteration (German umlauts, ligatures...). */
    private const REPLACEMENTS = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        'æ' => 'ae', 'Æ' => 'Ae', 'œ' => 'oe', 'Œ' => 'Oe', 'ø' => 'o', 'Ø' => 'O', 'ð' => 'd', 'Ð' => 'D',
        'þ' => 'th', 'Þ' => 'Th', 'ł' => 'l', 'Ł' => 'L', 'đ' => 'd', 'Đ' => 'D', 'ı' => 'i',
        '&' => ' and ', '🦄' => ' unicorn ', '♥' => ' love ',
    ];

    /** JS `String(value)` coercion for scalars, null and arrays. */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_float($value) && is_nan($value) => 'NaN',
            is_float($value) && floor($value) === $value && abs($value) < 1e21 => (string) (int) $value,
            is_array($value) => implode(',', array_map(self::stringify(...), $value)),
            $value instanceof \Stringable, is_scalar($value) => (string) $value,
            default => '[object Object]',
        };
    }

    /** Transliterate to ASCII the way @sindresorhus/slugify does (replacements, then deburr). */
    public static function transliterate(string $value): string
    {
        $value = strtr($value, self::REPLACEMENTS);

        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
            if (is_string($converted)) {
                return $converted;
            }
        }

        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($converted) ? $converted : $value;
    }

    /**
     * @param array{separator?: string, lowercase?: bool, decamelize?: bool} $options
     */
    public static function slugify(string $value, array $options = []): string
    {
        $separator = $options['separator'] ?? '-';
        $lowercase = $options['lowercase'] ?? true;
        $decamelize = $options['decamelize'] ?? true;

        $value = self::transliterate($value);

        if ($decamelize) {
            $value = (string) preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $value);
        }

        $quoted = preg_quote($separator, '/');
        $value = (string) preg_replace('/[^a-zA-Z\d]+/', $separator, $value);
        if ($separator !== '') {
            $value = (string) preg_replace("/{$quoted}{2,}/", $separator, $value);
            $value = (string) preg_replace("/^{$quoted}|{$quoted}$/", '', $value);
        }

        return $lowercase ? strtolower($value) : $value;
    }

    public static function nameToSlug(string $name, string $separator = '-'): string
    {
        return self::slugify($name, ['separator' => $separator]);
    }

    public static function nameToCollectionName(string $name): string
    {
        return self::slugify($name, ['separator' => '_']);
    }

    public static function toRegressedEnumValue(string $value): string
    {
        return self::slugify($value, ['decamelize' => false, 'lowercase' => false, 'separator' => '_']);
    }

    public static function getCommonPath(string ...$paths): string
    {
        if ($paths === []) {
            return '';
        }

        $segmentsList = array_map(static fn (string $p): array => explode('/', $p), $paths);
        $first = array_shift($segmentsList);

        $common = [];
        foreach ($first as $index => $segment) {
            foreach ($segmentsList as $other) {
                if (($other[$index] ?? null) !== $segment) {
                    break 2;
                }
            }
            $common[] = $segment;
        }

        return implode('/', $common);
    }

    public static function isEqual(mixed $a, mixed $b): bool
    {
        return self::stringify($a) === self::stringify($b);
    }

    public static function isCamelCase(string $value): bool
    {
        return preg_match('/^[a-z][a-zA-Z0-9]+$/', $value) === 1;
    }

    public static function isKebabCase(string $value): bool
    {
        return preg_match('/^([a-z][a-z0-9]*)(-[a-z0-9]+)*$/', $value) === 1;
    }

    public static function startsWithANumber(string $value): bool
    {
        return preg_match('/^[0-9]/', $value) === 1;
    }

    public static function joinBy(string $joint, string ...$args): string
    {
        $count = count($args);
        $url = '';

        foreach ($args as $index => $path) {
            if ($count === 1) {
                return $path;
            }
            if ($index === 0) {
                $url = self::trimEnd($path, $joint);
            } elseif ($index === $count - 1) {
                $url .= $joint . self::trimStart($path, $joint);
            } else {
                $url .= $joint . self::trimStart(self::trimEnd($path, $joint), $joint);
            }
        }

        return $url;
    }

    /** lodash `_.trimStart(value, chars)` where chars are removed one by one (any order). */
    public static function trimStart(string $value, string $chars): string
    {
        return $chars === '' ? ltrim($value) : ltrim($value, $chars);
    }

    public static function trimEnd(string $value, string $chars): string
    {
        return $chars === '' ? rtrim($value) : rtrim($value, $chars);
    }

    /**
     * lodash `_.words`: splits on case changes, digits, and non-alphanumerics.
     *
     * @return list<string>
     */
    public static function words(string $value): array
    {
        preg_match_all('/[A-Z]{2,}(?=[A-Z][a-z]+\d*|\b|[^a-zA-Z])|[A-Z]?[a-z]+\d*|[A-Z]+|\d+/u', self::transliterate($value), $m);

        return $m[0];
    }

    public static function toKebabCase(string $value): string
    {
        return self::kebabCase($value);
    }

    /** lodash `_.kebabCase`. */
    public static function kebabCase(string $value): string
    {
        return implode('-', array_map('strtolower', self::words($value)));
    }

    /** lodash `_.snakeCase`. */
    public static function snakeCase(string $value): string
    {
        return implode('_', array_map('strtolower', self::words($value)));
    }

    /** lodash `_.camelCase`. */
    public static function camelCase(string $value): string
    {
        $words = array_map('strtolower', self::words($value));
        $out = '';
        foreach ($words as $i => $word) {
            $out .= $i === 0 ? $word : ucfirst($word);
        }

        return $out;
    }

    /** lodash `_.upperFirst`. */
    public static function upperFirst(string $value): string
    {
        return ucfirst($value);
    }
}
