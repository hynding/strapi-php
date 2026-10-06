<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/utils/load-files.ts: builds a tree from files matching a glob
 * pattern in a directory (`components/<category>/<name>.json` → `[category][name] => contents`).
 */
final class LoadFiles
{
    /**
     * @param callable(string): bool|null $shouldUseFileNameAsKey
     * @return array<string, mixed>
     */
    public static function loadFiles(string $dir, string $pattern, ?callable $shouldUseFileNameAsKey = null): array
    {
        $root = [];
        $files = self::glob($dir, $pattern);

        foreach ($files as $file) {
            $absolutePath = $dir . '/' . $file;

            $mod = str_ends_with($absolutePath, '.json')
                ? json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR)
                : (static fn (): mixed => require $absolutePath)();

            if (is_array($mod)) {
                $mod['__filename__'] = basename($file);
            }

            $useFileNameAsKey = $shouldUseFileNameAsKey === null ? true : $shouldUseFileNameAsKey($file);
            $propPath = FilepathToPropPath::filePathToPropPath($file, $useFileNameAsKey);

            if ($propPath === []) {
                $root = Objects::merge($root, is_array($mod) ? $mod : []);
                continue;
            }

            $root = Objects::merge($root, Objects::set([], $propPath, $mod));
        }

        return $root;
    }

    /**
     * Minimal glob supporting `*`, `**` and `*.*(js|json)`-style alternations relative to $dir.
     *
     * @return list<string> relative paths
     */
    public static function glob(string $dir, string $pattern): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $regex = self::patternToRegex($pattern);
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen(rtrim($dir, '/')) + 1));
            if (preg_match($regex, $relative) === 1) {
                $out[] = $relative;
            }
        }
        sort($out);

        return $out;
    }

    private static function patternToRegex(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        // *(a|b) → (?:a|b)*, +(a|b) → (?:a|b)+ (extglob subset)
        $regex = preg_replace_callback('/([*+?@!])\(([^)]*)\)/', static function (array $m): string {
            $alts = implode('|', array_map('preg_quote', explode('|', $m[2])));
            $q = match ($m[1]) {
                '*' => '*', '+' => '+', '?' => '?', default => '',
            };

            return "\x00(?:{$alts}){$q}\x00";
        }, $pattern) ?? $pattern;

        $parts = preg_split('/\x00/', $regex) ?: [];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= $part; // already a regex fragment
                continue;
            }
            $quoted = preg_quote($part, '~');
            $quoted = str_replace('\*\*/', '(?:.*/)?', $quoted);
            $quoted = str_replace('\*\*', '.*', $quoted);
            $quoted = str_replace('\*', '[^/]*', $quoted);
            $quoted = str_replace('\?', '[^/]', $quoted);
            $out .= $quoted;
        }

        return '~^' . $out . '$~';
    }
}
