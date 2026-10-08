<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Format;

/**
 * PHP-only: the subset of `chalk` (npm) that upstream's upgrade tool uses — ANSI styles that
 * compose (`Chalk::bold(Chalk::cyan('x'))` is `chalk.bold.cyan('x')`), disabled when the output
 * doesn't support colors (`supports-color`'s rules: `FORCE_COLOR`, `NO_COLOR`, a TTY).
 */
final class Chalk
{
    /** null: detect on first use */
    public static ?bool $enabled = null;

    private const STYLES = [
        'bold' => [1, 22],
        'dim' => [2, 22],
        'italic' => [3, 23],
        'underline' => [4, 24],
        'red' => [31, 39],
        'green' => [32, 39],
        'yellow' => [33, 39],
        'blue' => [34, 39],
        'magenta' => [35, 39],
        'cyan' => [36, 39],
        'grey' => [90, 39],
    ];

    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            $force = getenv('FORCE_COLOR');
            self::$enabled = match (true) {
                $force !== false && $force !== '' => $force !== '0' && $force !== 'false',
                getenv('NO_COLOR') !== false => false,
                getenv('TERM') === 'dumb' => false,
                default => defined('STDOUT') && stream_isatty(STDOUT),
            };
        }

        return self::$enabled;
    }

    public static function style(string $style, mixed $text): string
    {
        $text = self::stringify($text);
        if (!self::enabled()) {
            return $text;
        }

        [$open, $close] = self::STYLES[$style];

        // like chalk, re-open the style after any nested close of the same code
        return "\e[{$open}m" . str_replace("\e[{$close}m", "\e[{$close}m\e[{$open}m", $text) . "\e[{$close}m";
    }

    public static function bold(mixed $text): string
    {
        return self::style('bold', $text);
    }

    public static function dim(mixed $text): string
    {
        return self::style('dim', $text);
    }

    public static function italic(mixed $text): string
    {
        return self::style('italic', $text);
    }

    public static function underline(mixed $text): string
    {
        return self::style('underline', $text);
    }

    public static function red(mixed $text): string
    {
        return self::style('red', $text);
    }

    public static function green(mixed $text): string
    {
        return self::style('green', $text);
    }

    public static function yellow(mixed $text): string
    {
        return self::style('yellow', $text);
    }

    public static function blue(mixed $text): string
    {
        return self::style('blue', $text);
    }

    public static function magenta(mixed $text): string
    {
        return self::style('magenta', $text);
    }

    public static function cyan(mixed $text): string
    {
        return self::style('cyan', $text);
    }

    public static function grey(mixed $text): string
    {
        return self::style('grey', $text);
    }

    /** Removes ANSI escape sequences. */
    public static function strip(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
    }

    /** `String(value)` for the values the tool prints */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
