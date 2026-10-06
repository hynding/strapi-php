<?php

declare(strict_types=1);

namespace Strapi\Database\Utils;

/**
 * Faithful port of lodash `_.words` / `_.snakeCase` (the unicode path of lodash 4.17).
 *
 * Database identifiers are derived with lodash's snakeCase upstream, and they must be
 * byte-identical to what Node Strapi produces: "componen56bca" splits into
 * `componen`, `56`, `bca` (digits are their own words), which the lighter
 * `Strapi\Utils\Primitives\Strings::snakeCase` does not reproduce.
 */
final class LodashWords
{
    private static ?string $unicodeRegex = null;

    /** @return list<string> */
    public static function words(string $value): array
    {
        $value = (string) preg_replace("/['\x{2019}]/u", '', $value);

        // hasUnicodeWord: only the ASCII fast path when nothing needs the unicode regex
        if (preg_match('/[a-z][A-Z]|[A-Z]{2}[a-z]|[0-9][a-zA-Z]|[a-zA-Z][0-9]|[^a-zA-Z0-9 ]/', $value) !== 1) {
            preg_match_all('/[^\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\x7f]+/', $value, $m);

            return $m[0];
        }

        preg_match_all(self::unicodeRegex(), $value, $m);

        return $m[0];
    }

    public static function snakeCase(string $value): string
    {
        return implode('_', array_map(static fn (string $w): string => mb_strtolower($w), self::words($value)));
    }

    public static function kebabCase(string $value): string
    {
        return implode('-', array_map(static fn (string $w): string => mb_strtolower($w), self::words($value)));
    }

    public static function camelCase(string $value): string
    {
        $out = '';
        foreach (self::words($value) as $i => $word) {
            $word = mb_strtolower($word);
            $out .= $i === 0 ? $word : mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
        }

        return $out;
    }

    public static function upperFirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    private static function unicodeRegex(): string
    {
        if (self::$unicodeRegex !== null) {
            return self::$unicodeRegex;
        }

        $astral = '\x{10000}-\x{10FFFF}';
        $comboRange = '\x{0300}-\x{036f}\x{fe20}-\x{fe2f}\x{20d0}-\x{20ff}\x{1ab0}-\x{1aff}\x{1dc0}-\x{1dff}';
        $dingbatRange = '\x{2700}-\x{27bf}';
        $lowerRange = 'a-z\x{df}-\x{f6}\x{f8}-\x{ff}';
        $mathOpRange = '\x{ac}\x{b1}\x{d7}\x{f7}';
        $nonCharRange = '\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\x{bf}';
        $punctuationRange = '\x{2000}-\x{206f}';
        $spaceRange = ' \t\x0b\f\x{a0}\x{feff}\n\r\x{2028}\x{2029}\x{1680}\x{180e}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}';
        $upperRange = 'A-Z\x{c0}-\x{d6}\x{d8}-\x{de}';
        $varRange = '\x{fe0e}\x{fe0f}';
        $breakRange = $mathOpRange . $nonCharRange . $punctuationRange . $spaceRange;

        $apos = "['\x{2019}]";
        $break = '[' . $breakRange . ']';
        $combo = '[' . $comboRange . ']';
        $digits = '\d+';
        $dingbat = '[' . $dingbatRange . ']';
        $lower = '[' . $lowerRange . ']';
        $misc = '[^' . $astral . $breakRange . '\d' . $dingbatRange . $lowerRange . $upperRange . ']';
        $fitz = '[\x{1f3fb}-\x{1f3ff}]';
        $modifier = '(?:' . $combo . '|' . $fitz . ')';
        $nonAstral = '[^' . $astral . ']';
        $regional = '(?:[\x{1f1e6}-\x{1f1ff}]){2}';
        $surrPair = '[' . $astral . ']';
        $upper = '[' . $upperRange . ']';
        $zwj = '\x{200d}';

        $miscLower = '(?:' . $lower . '|' . $misc . ')';
        $miscUpper = '(?:' . $upper . '|' . $misc . ')';
        $optContrLower = '(?:' . $apos . '(?:d|ll|m|re|s|t|ve))?';
        $optContrUpper = '(?:' . $apos . '(?:D|LL|M|RE|S|T|VE))?';
        $optMod = $modifier . '?';
        $optVar = '[' . $varRange . ']?';
        $optJoin = '(?:' . $zwj . '(?:' . implode('|', [$nonAstral, $regional, $surrPair]) . ')' . $optVar . $optMod . ')*';
        $ordLower = '\d*(?:1st|2nd|3rd|(?![123])\dth)(?=\b|[A-Z_])';
        $ordUpper = '\d*(?:1ST|2ND|3RD|(?![123])\dTH)(?=\b|[a-z_])';
        $seq = $optVar . $optMod . $optJoin;
        $emoji = '(?:' . implode('|', [$dingbat, $regional, $surrPair]) . ')' . $seq;

        return self::$unicodeRegex = '/' . implode('|', [
            $upper . '?' . $lower . '+' . $optContrLower . '(?=' . implode('|', [$break, $upper, '$']) . ')',
            $miscUpper . '+' . $optContrUpper . '(?=' . implode('|', [$break, $upper . $miscLower, '$']) . ')',
            $upper . '?' . $miscLower . '+' . $optContrLower,
            $upper . '+' . $optContrUpper,
            $ordUpper,
            $ordLower,
            $digits,
            $emoji,
        ]) . '/u';
    }
}
