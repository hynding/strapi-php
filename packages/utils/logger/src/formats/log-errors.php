<?php

declare(strict_types=1);

namespace Strapi\Logger\Formats;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Port of formats/log-errors.ts: when the record carries a Throwable (as `context['exception']`,
 * the Monolog convention, or as the message itself), append its stack trace to the message.
 */
final class LogErrors implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $error = $record->context['exception'] ?? null;

        if (!$error instanceof \Throwable) {
            return $record;
        }

        $message = $record->message !== '' ? $record->message : $error->getMessage();
        $stack = $error->getTraceAsString();

        return $record->with(message: $message . ($stack !== '' ? "\n{$stack}" : ''));
    }
}
