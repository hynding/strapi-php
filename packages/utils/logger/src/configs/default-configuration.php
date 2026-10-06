<?php

declare(strict_types=1);

namespace Strapi\Logger\Configs;

use Monolog\Handler\StreamHandler;
use Strapi\Logger\Constants;
use Strapi\Logger\Formats\PrettyPrint;

/**
 * Port of configs/default-configuration.ts: level `silly` (everything), pretty-print format, a
 * console (stdout) transport.
 *
 * @phpstan-type LoggerConfiguration array{level?: string, levels?: array<string, int>, format?: \Monolog\Formatter\FormatterInterface, transports?: list<\Monolog\Handler\HandlerInterface>, processors?: list<callable|\Monolog\Processor\ProcessorInterface>, name?: string, stream?: string|resource}
 */
final class DefaultConfiguration
{
    /**
     * @param string|resource $stream  where the console transport writes (default `php://stdout`)
     * @return LoggerConfiguration
     */
    public static function create(mixed $stream = 'php://stdout'): array
    {
        $format = new PrettyPrint();
        $console = new StreamHandler($stream, Constants::toMonologLevel(Constants::LEVEL_LABEL));
        $console->setFormatter($format);

        return [
            'level' => Constants::LEVEL_LABEL,
            'levels' => Constants::LEVELS,
            'format' => $format,
            'transports' => [$console],
        ];
    }

    /** @return LoggerConfiguration */
    public function __invoke(mixed $stream = 'php://stdout'): array
    {
        return self::create($stream);
    }
}
