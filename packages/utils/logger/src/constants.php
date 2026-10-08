<?php

declare(strict_types=1);

namespace Strapi\Logger;

use Monolog\Level;

/**
 * Port of packages/utils/logger/src/constants.ts.
 *
 * Winston's npm levels (`error: 0 ... silly: 6`) mapped onto Monolog levels. Winston logs everything at
 * or *below* the configured number (lower = more severe), Monolog everything at or *above* its level,
 * so the default label `silly` becomes `Level::Debug` (log everything).
 */
final class Constants
{
    /** Winston npm levels: name → priority (lower is more severe). */
    public const LEVELS = [
        'error' => 0,
        'warn' => 1,
        'info' => 2,
        'http' => 3,
        'verbose' => 4,
        'debug' => 5,
        'silly' => 6,
    ];

    public const LEVEL_LABEL = 'silly';

    public const LEVEL = self::LEVELS[self::LEVEL_LABEL];

    /** Winston level label → Monolog level. */
    public const MONOLOG_LEVELS = [
        'error' => Level::Error,
        'warn' => Level::Warning,
        'info' => Level::Info,
        'http' => Level::Info,
        'verbose' => Level::Debug,
        'debug' => Level::Debug,
        'silly' => Level::Debug,
    ];

    /** Resolve a winston label (or a Monolog level / name) to a Monolog level. */
    public static function toMonologLevel(string|int|Level $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }
        if (is_int($level)) {
            return Level::from($level);
        }

        $lower = strtolower($level);
        if (isset(self::MONOLOG_LEVELS[$lower])) {
            return self::MONOLOG_LEVELS[$lower];
        }

        foreach (Level::cases() as $case) {
            if (strtolower($case->name) === $lower) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown log level "%s"', $level));
    }

    /** The winston label for a Monolog level (what `level` shows in log lines, lowercased like winston). */
    public static function toWinstonLabel(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info, Level::Notice => 'info',
            Level::Warning => 'warn',
            default => 'error',
        };
    }
}
