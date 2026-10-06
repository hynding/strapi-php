<?php

declare(strict_types=1);

namespace Strapi\Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\ProcessorInterface;
use Strapi\Logger\Configs\DefaultConfiguration;

/**
 * Port of packages/utils/logger/src/index.ts: `createLogger(userConfiguration)` returns a Monolog
 * logger built from the default configuration overridden by the user's (`config/logger.php`).
 *
 * Configuration keys (winston names kept): `level` (winston label), `format` (a Monolog formatter
 * applied to transports that have none), `transports` (Monolog handlers), `processors`, `name`.
 *
 * @phpstan-import-type LoggerConfiguration from DefaultConfiguration
 */
final class Logger
{
    public const DEFAULT_NAME = 'strapi';

    /** @param LoggerConfiguration $userConfiguration */
    public static function createLogger(array $userConfiguration = []): MonologLogger
    {
        $configuration = array_merge(DefaultConfiguration::create($userConfiguration['stream'] ?? 'php://stdout'), $userConfiguration);

        $level = Constants::toMonologLevel($configuration['level'] ?? Constants::LEVEL_LABEL);
        $format = $configuration['format'] ?? null;

        $handlers = [];
        foreach ($configuration['transports'] ?? [] as $handler) {
            if (!$handler instanceof HandlerInterface) {
                throw new \InvalidArgumentException('Logger transports must be Monolog handlers, got ' . get_debug_type($handler));
            }
            if ($format !== null && $handler instanceof \Monolog\Handler\FormattableHandlerInterface && !self::hasExplicitFormatter($handler)) {
                $handler->setFormatter($format);
            }
            // the logger level applies on top of each transport's own level (winston: `level` is the minimum)
            $handlers[] = $handler instanceof \Monolog\Handler\AbstractHandler && $handler->getLevel()->isLowerThan($level)
                ? $handler->setLevel($level)
                : $handler;
        }

        $processors = [];
        foreach ($configuration['processors'] ?? [] as $processor) {
            if (!$processor instanceof ProcessorInterface && !is_callable($processor)) {
                throw new \InvalidArgumentException('Logger processors must be callables or Monolog processors');
            }
            $processors[] = $processor;
        }

        return new MonologLogger($configuration['name'] ?? self::DEFAULT_NAME, $handlers, $processors);
    }

    /** @param LoggerConfiguration $userConfiguration */
    public function __invoke(array $userConfiguration = []): MonologLogger
    {
        return self::createLogger($userConfiguration);
    }

    private static function hasExplicitFormatter(\Monolog\Handler\FormattableHandlerInterface $handler): bool
    {
        if (!$handler instanceof \Monolog\Handler\AbstractProcessingHandler) {
            return false;
        }

        // `formatter` is declared by FormattableHandlerTrait; reflect on the concrete class to read it
        $property = new \ReflectionProperty($handler, 'formatter');

        return $property->getValue($handler) !== null;
    }
}
