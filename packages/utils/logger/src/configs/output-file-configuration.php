<?php

declare(strict_types=1);

namespace Strapi\Logger\Configs;

use Monolog\Handler\StreamHandler;
use Strapi\Logger\Constants;
use Strapi\Logger\Formats\ExcludeColors;
use Strapi\Logger\Formats\PrettyPrint;

/**
 * Port of configs/output-file-configuration.ts: a console transport (optionally with its own level)
 * plus a file transport logging `error` and above as plain text.
 *
 * @phpstan-import-type LoggerConfiguration from DefaultConfiguration
 */
final class OutputFileConfiguration
{
    /**
     * @param array{level?: string} $fileTransportOptions
     * @param array{consoleLevel?: string} $options
     * @param string|resource $consoleStream
     * @return LoggerConfiguration
     */
    public static function create(string $filename, array $fileTransportOptions = [], array $options = [], mixed $consoleStream = 'php://stdout'): array
    {
        $consoleLevel = $options['consoleLevel'] ?? Constants::LEVEL_LABEL;
        $format = new PrettyPrint();

        $console = new StreamHandler($consoleStream, Constants::toMonologLevel($consoleLevel));
        $console->setFormatter($format);

        $file = new StreamHandler($filename, Constants::toMonologLevel($fileTransportOptions['level'] ?? 'error'));
        $file->setFormatter(new ExcludeColors());

        return [
            'level' => Constants::LEVEL_LABEL,
            'levels' => Constants::LEVELS,
            'format' => $format,
            'transports' => [$console, $file],
        ];
    }

    /**
     * @param array{level?: string} $fileTransportOptions
     * @param array{consoleLevel?: string} $options
     * @return LoggerConfiguration
     */
    public function __invoke(string $filename, array $fileTransportOptions = [], array $options = []): array
    {
        return self::create($filename, $fileTransportOptions, $options);
    }
}
