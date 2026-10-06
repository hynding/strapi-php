<?php

declare(strict_types=1);

namespace Strapi\Logger\Formats;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Strapi\Logger\Constants;

/**
 * Port of formats/detailed-log.ts: `[timestamp] level: message` with ANSI colors stripped.
 */
final class DetailedLog implements FormatterInterface
{
    public function __construct(private readonly string $timestampFormat = PrettyPrint::DEFAULT_TIMESTAMP_FORMAT)
    {
    }

    public function format(LogRecord $record): string
    {
        $line = sprintf('[%s] %s: %s', $record->datetime->format($this->timestampFormat), Constants::toWinstonLabel($record->level), $record->message);

        return ExcludeColors::strip($line) . "\n";
    }

    /** @param array<LogRecord> $records */
    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }
}
