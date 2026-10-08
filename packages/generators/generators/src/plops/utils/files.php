<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/** Not an upstream file: the fs-extra helpers the generators use (`outputFile`, `outputJSON`). */
final class Files
{
    /** fs-extra `outputFile`: write, creating the parent directories. */
    public static function outputFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException("EACCES: permission denied, mkdir '{$dir}'");
        }
        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("EACCES: permission denied, open '{$path}'");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /** fs-extra `outputJSON(path, value, { spaces: 2 })`. */
    public static function outputJSON(string $path, mixed $value): void
    {
        self::outputFile($path, self::stringify($value) . "\n");
    }

    /** `JSON.stringify(value, null, 2)`. */
    public static function stringify(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        // json_encode indents with four spaces; strings never contain a raw newline
        return (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);
    }
}
