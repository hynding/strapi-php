<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/** Mirrors engine/files.js: the few file helpers the engine needs, with `/` paths relative to a root. */
final class Files
{
    /** @return array<string, mixed>|null null when missing or not a JSON object */
    public static function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** JSON.stringify(value, null, 2) + newline, the way npm writes package.json. */
    public static function encodeJson(mixed $value, int $indent = 2): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($indent !== 4) {
            $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', (int) (strlen($m[0]) / 4 * $indent)), $json);
        }

        return $json . "\n";
    }

    /** The absolute project root without a trailing slash. */
    public static function realRoot(string $root): string
    {
        $real = realpath($root);

        return rtrim($real === false ? $root : $real, '/');
    }

    public static function read(string $file): string
    {
        return (string) file_get_contents($file);
    }

    public static function write(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($file, $content);
    }

    /**
     * Every file under `$root`, as `/`-separated relative paths in sorted order, skipping the
     * directories the spec ignores.
     *
     * @return list<string>
     */
    public static function walk(string $root): array
    {
        $out = [];
        $visit = static function (string $dir) use (&$visit, &$out, $root): void {
            $entries = scandir($dir) ?: [];
            sort($entries, SORT_STRING);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $abs = $dir . '/' . $entry;
                $rel = ltrim(substr($abs, strlen($root)), '/');
                if (Spec::isIgnored($rel)) {
                    continue;
                }
                if (is_dir($abs)) {
                    $visit($abs);
                } else {
                    $out[] = $rel;
                }
            }
        };
        $visit($root);
        sort($out, SORT_STRING);

        return $out;
    }

    /** `config/server.js` → `js` */
    public static function extension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /** `config/server.js` → `config/server` */
    public static function stem(string $path): string
    {
        $ext = self::extension($path);

        return $ext === '' ? $path : substr($path, 0, -strlen($ext) - 1);
    }

    /** How many directories deep a relative path is: `config/server.php` → 1. */
    public static function depth(string $path): int
    {
        return substr_count($path, '/');
    }
}
