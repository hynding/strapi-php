<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

/**
 * Port of packages/core/strapi/src/cli/utils/logger.ts (`createLogger`). Spinners and progress
 * bars are plain lines (no ora / cli-progress).
 */
final class Logger
{
    private int $warnings = 0;

    private int $errors = 0;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(
        private readonly bool $silent = false,
        private readonly bool $debug = false,
        private readonly bool $timestamp = true,
        $out = null,
        $err = null,
    ) {
        $this->out = $out ?? (defined('STDOUT') ? STDOUT : fopen('php://output', 'wb'));
        $this->err = $err ?? (defined('STDERR') ? STDERR : fopen('php://output', 'wb'));
    }

    /** @param array{silent?: bool, debug?: bool, timestamp?: bool} $options */
    public static function createLogger(array $options = []): self
    {
        return new self($options['silent'] ?? false, $options['debug'] ?? false, $options['timestamp'] ?? true);
    }

    public function warnings(): int
    {
        return $this->warnings;
    }

    public function errors(): int
    {
        return $this->errors;
    }

    public function isSilent(): bool
    {
        return $this->silent;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    private function prefix(string $label, string $color): string
    {
        $stamp = $this->timestamp ? "\t[" . gmdate('Y-m-d\TH:i:s.v\Z') . ']' : '';

        return self::colorize("[{$label}]{$stamp}", $color);
    }

    public static function colorize(string $text, string $color): string
    {
        if (!self::useColors()) {
            return $text;
        }
        $codes = ['cyan' => '36', 'blue' => '34', 'green' => '32', 'yellow' => '33', 'red' => '31'];

        return "\033[" . ($codes[$color] ?? '0') . "m{$text}\033[0m";
    }

    public static function useColors(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return defined('STDOUT') && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    private static function format(mixed ...$args): string
    {
        return implode(' ', array_map(static fn (mixed $arg): string => is_string($arg) ? $arg : (is_scalar($arg) || $arg === null ? var_export($arg, true) : (string) json_encode($arg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)), $args));
    }

    public function debug(mixed ...$args): void
    {
        if ($this->silent || !$this->debug) {
            return;
        }
        fwrite($this->out, $this->prefix('DEBUG', 'cyan') . ' ' . self::format(...$args) . "\n");
    }

    public function info(mixed ...$args): void
    {
        if ($this->silent) {
            return;
        }
        fwrite($this->out, $this->prefix('INFO', 'blue') . ' ' . self::format(...$args) . "\n");
    }

    public function log(mixed ...$args): void
    {
        if ($this->silent) {
            return;
        }
        fwrite($this->out, self::format(...$args) . "\n");
    }

    public function success(mixed ...$args): void
    {
        if ($this->silent) {
            return;
        }
        fwrite($this->out, $this->prefix('SUCCESS', 'green') . ' ' . self::format(...$args) . "\n");
    }

    public function warn(mixed ...$args): void
    {
        $this->warnings++;
        if ($this->silent) {
            return;
        }
        fwrite($this->err, $this->prefix('WARN', 'yellow') . ' ' . self::format(...$args) . "\n");
    }

    public function error(mixed ...$args): void
    {
        $this->errors++;
        if ($this->silent) {
            return;
        }
        fwrite($this->err, $this->prefix('ERROR', 'red') . ' ' . self::format(...$args) . "\n");
    }

    /** ora replacement: `['start' => fn, 'succeed' => fn, 'fail' => fn]` printing one line each. */
    public function spinner(string $text): Spinner
    {
        return new Spinner($this, $text);
    }
}
