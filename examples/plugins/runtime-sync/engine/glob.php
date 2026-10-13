<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/glob.js. The only glob syntax spec/facets.json uses: `*` matches within one
 * path segment, `**` matches any number of segments (including none). Paths are relative to the
 * project root, with `/` separators.
 */
final class Glob
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function match(string $pattern, string $path): bool
    {
        return preg_match(self::toRegex($pattern), $path) === 1;
    }

    public static function toRegex(string $pattern): string
    {
        if (isset(self::$cache[$pattern])) {
            return self::$cache[$pattern];
        }

        $regex = '';
        $parts = preg_split('#(\*\*/|\*\*|\*)#', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $regex .= match ($part) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                default => preg_quote($part, '#'),
            };
        }

        return self::$cache[$pattern] = '#^' . $regex . '$#';
    }
}
