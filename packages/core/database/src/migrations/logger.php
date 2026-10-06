<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

/** Port of packages/core/database/src/migrations/logger.ts. */
final class Logger
{
    /** Formats a runner event and forwards it to a PSR logger. */
    public static function log(\Psr\Log\LoggerInterface $logger, string $level, mixed $message): void
    {
        $formatted = self::transformLogMessage($level, $message);
        if ($formatted === '') {
            return;
        }

        $logger->log($level, $formatted['message'], array_diff_key($formatted, ['message' => true, 'level' => true]));
    }

    /** @return array{level: string, message: string, timestamp?: int}|string */
    public static function transformLogMessage(string $level, mixed $message): array|string
    {
        if (is_string($message)) {
            return ['level' => $level, 'message' => $message];
        }

        if (is_array($message) && isset($message['event'], $message['name'])) {
            $text = "[internal migration]: {$message['event']} {$message['name']}";
            $duration = $message['durationSeconds'] ?? null;
            if (is_int($duration) || is_float($duration)) {
                $text .= sprintf(' (%.3fs)', $duration);
            }

            return ['level' => $level, 'message' => $text, 'timestamp' => (int) floor(microtime(true) * 1000)];
        }

        return '';
    }
}
