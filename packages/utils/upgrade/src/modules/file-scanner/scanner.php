<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\FileScanner;

/**
 * Port of packages/utils/upgrade/src/modules/file-scanner/scanner.ts. Upstream delegates to
 * `fast-glob`; this ports the subset of its pattern syntax the tool uses: `*`, `**`, `?`,
 * `{a,b}` alternatives, a leading `./`, `!` negations, and fast-glob's default of not matching
 * dot files with wildcards. Results are absolute paths, sorted.
 */
final class FileScanner
{
    public function __construct(public readonly string $cwd)
    {
    }

    public static function fileScannerFactory(string $cwd): self
    {
        return new self($cwd);
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    public function scan(array $patterns): array
    {
        $positive = [];
        $negative = [];
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                $negative[] = self::toRegex(self::normalize(substr($pattern, 1)));
            } else {
                $positive[] = self::normalize($pattern);
            }
        }

        $found = [];
        foreach ($positive as $pattern) {
            $regex = self::toRegex($pattern);

            // walk each static base directory once (`{src,config}/**` walks src/ and config/)
            $bases = [];
            foreach (self::expandBraces($pattern) as $expanded) {
                $bases[self::staticBase($expanded)] = $expanded;
            }

            foreach ($bases as $base => $expanded) {
                $base = (string) $base;
                $root = $this->cwd . ($base === '' ? '' : DIRECTORY_SEPARATOR . $base);

                if ($base === $expanded) {
                    // a literal path
                    if (is_file($root)) {
                        $found[$base] = true;
                    }
                    continue;
                }

                if (!is_dir($root)) {
                    continue;
                }

                foreach ($this->walk($root, $base, $negative) as $relative) {
                    if (preg_match($regex, $relative) === 1) {
                        $found[$relative] = true;
                    }
                }
            }
        }

        $files = [];
        foreach (array_keys($found) as $relative) {
            $relative = (string) $relative;
            foreach ($negative as $regex) {
                if (preg_match($regex, $relative) === 1) {
                    continue 2;
                }
            }
            // Resolve the full paths for every filename
            $files[] = $this->cwd . DIRECTORY_SEPARATOR . $relative;
        }

        sort($files);

        return $files;
    }

    private static function normalize(string $pattern): string
    {
        while (str_starts_with($pattern, './')) {
            $pattern = substr($pattern, 2);
        }

        return $pattern;
    }

    /** the leading directories without glob characters */
    private static function staticBase(string $pattern): string
    {
        $segments = explode('/', $pattern);
        $base = [];
        foreach ($segments as $segment) {
            if (preg_match('/[*?{}\[\]!]/', $segment) === 1) {
                return implode('/', $base);
            }
            $base[] = $segment;
        }

        return implode('/', $base);
    }

    /**
     * @param list<string> $negative regexes; a directory whose children they all exclude is not entered
     * @return \Generator<int, string> paths relative to the cwd, `/`-separated
     */
    private function walk(string $directory, string $relative, array $negative): \Generator
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $rel = $relative === '' ? $entry : "{$relative}/{$entry}";
            if (is_dir($path) && !is_link($path)) {
                foreach ($negative as $regex) {
                    if (preg_match($regex, "{$rel}/x") === 1 && preg_match($regex, "{$rel}/x/x.x") === 1) {
                        continue 2;
                    }
                }
                yield from $this->walk($path, $rel, $negative);
            } elseif (is_file($path)) {
                yield $rel;
            }
        }
    }

    /**
     * `a/{b,c}/*.{d,e}` → `a/b/*.d`, `a/b/*.e`, `a/c/*.d`, `a/c/*.e` (brace groups are not nested)
     *
     * @return list<string>
     */
    private static function expandBraces(string $pattern): array
    {
        if (preg_match('/\{([^{}]*)\}/', $pattern, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [$pattern];
        }

        [$group, $offset] = $m[0];
        $expanded = [];
        foreach (explode(',', $m[1][0]) as $alternative) {
            $expanded = [...$expanded, ...self::expandBraces(substr_replace($pattern, $alternative, $offset, strlen($group)))];
        }

        return $expanded;
    }

    /** glob → anchored regex over `/`-separated relative paths */
    public static function toRegex(string $pattern): string
    {
        $segments = explode('/', $pattern);
        $parts = [];
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '**') {
                // zero or more directories, none of them dot directories
                $parts[] = $i === $last ? '(?:[^/.][^/]*(?:/[^/.][^/]*)*)?' : '(?:[^/.][^/]*/)*';
                continue;
            }
            $parts[] = self::segmentToRegex($segment) . ($i === $last ? '' : '/');
        }

        return '#^' . implode('', $parts) . '$#';
    }

    private static function segmentToRegex(string $segment): string
    {
        $regex = '';
        $length = strlen($segment);
        $depth = 0;
        for ($i = 0; $i < $length; ++$i) {
            $char = $segment[$i];
            if ($char === '{') {
                ++$depth;
                $regex .= '(?:';
            } elseif ($char === '}' && $depth > 0) {
                --$depth;
                $regex .= ')';
            } elseif ($char === ',' && $depth > 0) {
                $regex .= '|';
            } else {
                $regex .= match ($char) {
                    '*' => '[^/]*',
                    '?' => '[^/]',
                    default => preg_quote($char, '#'),
                };
            }
        }

        // wildcards don't match a leading dot unless the pattern spells it out
        return str_starts_with($segment, '.') ? $regex : '(?!\.)' . $regex;
    }
}
