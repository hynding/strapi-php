<?php

declare(strict_types=1);

namespace Strapi\Logger\Formats;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Port of formats/exclude-colors.ts: strips ANSI color codes from the message. Used for the
 * file transport so plain text is logged.
 */
final class ExcludeColors implements FormatterInterface
{
    public const ANSI_REGEX = '/[\x{001b}\x{009b}][[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/u';

    public static function strip(string $message): string
    {
        return (string) preg_replace(self::ANSI_REGEX, '', $message);
    }

    public function format(LogRecord $record): string
    {
        return self::strip($record->message) . "\n";
    }

    /** @param array<LogRecord> $records */
    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }
}
