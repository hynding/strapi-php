<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Json;

/**
 * Port of packages/utils/upgrade/src/modules/json/file.ts.
 *
 * PHP-only: `saveJSON()` keeps the file's existing indentation (Composer writes `composer.json`
 * with four spaces, npm writes `package.json` with two); a new file gets upstream's two.
 */
final class File
{
    public static function readJSON(string $path): mixed
    {
        $buffer = @file_get_contents($path);
        if ($buffer === false) {
            throw new \RuntimeException("ENOENT: no such file or directory, open '{$path}'");
        }

        return self::parse($buffer);
    }

    /** `JSON.parse`, keeping empty objects as `\stdClass` */
    public static function parse(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return $value;
            }

            return array_map(self::normalize(...), $vars);
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }

    public static function saveJSON(string $path, mixed $json, ?int $indent = null): void
    {
        $indent ??= self::detectIndent($path) ?? 2;

        if (file_put_contents($path, self::stringify($json, $indent) . "\n") === false) {
            throw new \RuntimeException("Could not write {$path}");
        }
    }

    /** `JSON.stringify(json, null, indent)` */
    public static function stringify(mixed $json, int $indent = 2): string
    {
        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if ($indent === 4) {
            return $encoded;
        }

        // json_encode indents with four spaces; JSON strings cannot hold a raw newline, so every
        // leading run of spaces is indentation
        return (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 4) * $indent), $encoded);
    }

    private static function detectIndent(string $path): ?int
    {
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        return preg_match('/^\{\s*\n( +)\S/', $content, $m) === 1 ? strlen($m[1]) : null;
    }
}
