<?php

declare(strict_types=1);

namespace Strapi\Logger\Formats;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Strapi\Logger\Constants;

/**
 * Port of formats/pretty-print.ts: `[YYYY-MM-DD HH:mm:ss.SSS] level: message`, with the level
 * colorized (winston's default colors: error red, warn yellow, info green, debug blue) and error
 * stack traces appended ({@see LogErrors}).
 *
 * Options: `timestamps` (bool, or a PHP date format string), `colors` (bool).
 */
final class PrettyPrint implements FormatterInterface
{
    /** winston `YYYY-MM-DD HH:mm:ss.SSS` */
    public const DEFAULT_TIMESTAMP_FORMAT = 'Y-m-d H:i:s.v';

    private const COLORS = [
        'error' => "\033[31m",
        'warn' => "\033[33m",
        'info' => "\033[32m",
        'http' => "\033[32m",
        'verbose' => "\033[36m",
        'debug' => "\033[34m",
        'silly' => "\033[35m",
    ];

    private const RESET = "\033[39m";

    private readonly string|false $timestampFormat;

    private readonly bool $colors;

    private readonly LogErrors $logErrors;

    /** @param array{timestamps?: bool|string, colors?: bool} $options */
    public function __construct(array $options = [])
    {
        $timestamps = $options['timestamps'] ?? true;
        $this->timestampFormat = $timestamps === false ? false : ($timestamps === true ? self::DEFAULT_TIMESTAMP_FORMAT : $timestamps);
        $this->colors = $options['colors'] ?? true;
        $this->logErrors = new LogErrors();
    }

    public static function colorize(string $label): string
    {
        return (self::COLORS[$label] ?? '') . $label . self::RESET;
    }

    public function format(LogRecord $record): string
    {
        $record = ($this->logErrors)($record);
        $label = Constants::toWinstonLabel($record->level);
        $level = $this->colors ? self::colorize($label) : $label;
        $timestamp = $this->timestampFormat === false ? '' : '[' . $record->datetime->format($this->timestampFormat) . '] ';

        return "{$timestamp}{$level}: {$record->message}\n";
    }

    /** @param array<LogRecord> $records */
    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }
}
