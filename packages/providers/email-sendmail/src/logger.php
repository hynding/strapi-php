<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail;

/**
 * Port of src/logger.ts.
 *
 * Matches guileen/node-sendmail: `options.logger` wins over `silent` — if a custom logger is
 * set, it is used as-is. Only when there is no custom logger does `silent` suppress output.
 * Strapi merges `{ silent: true, ...providerOptions }` so explicit `silent: false` still applies.
 *
 * `options.logger` is an array of callables (`debug`, `info`, `warn`, `error`) or an object with
 * those methods (a PSR-3 logger's `warning` stands in for `warn`). Without one, output goes to the
 * PHP error log (`console.*` upstream).
 */
final class Logger
{
    /** @var \Closure(mixed...): void */
    public readonly \Closure $debug;

    /** @var \Closure(mixed...): void */
    public readonly \Closure $info;

    /** @var \Closure(mixed...): void */
    public readonly \Closure $warn;

    /** @var \Closure(mixed...): void */
    public readonly \Closure $error;

    private function __construct(?\Closure $debug, ?\Closure $info, ?\Closure $warn, ?\Closure $error)
    {
        $noop = static function (mixed ...$args): void {
        };
        $this->debug = $debug ?? $noop;
        $this->info = $info ?? $noop;
        $this->warn = $warn ?? $noop;
        $this->error = $error ?? $noop;
    }

    /** @param array<string, mixed> $options */
    public static function createLogger(array $options): self
    {
        $logger = $options['logger'] ?? null;
        if (is_array($logger) || is_object($logger)) {
            $method = static function (string ...$names) use ($logger): ?\Closure {
                foreach ($names as $name) {
                    if (is_array($logger) && is_callable($logger[$name] ?? null)) {
                        return \Closure::fromCallable($logger[$name]);
                    }
                    if (is_object($logger) && method_exists($logger, $name)) {
                        return static function (mixed ...$args) use ($logger, $name): void {
                            if ($logger instanceof \Psr\Log\LoggerInterface) {
                                $message = array_shift($args);
                                $logger->{$name}(self::stringify($message), $args === [] ? [] : ['args' => $args]);

                                return;
                            }
                            $logger->{$name}(...$args);
                        };
                    }
                }

                return null;
            };

            return new self($method('debug'), $method('info'), $method('warn', 'warning'), $method('error'));
        }

        $silent = ($options['silent'] ?? null) === true;
        if ($silent) {
            return new self(null, null, null, null);
        }

        $console = static fn (string $level): \Closure => static function (mixed ...$args) use ($level): void {
            error_log("[{$level}] " . implode(' ', array_map(self::stringify(...), $args)));
        };

        return new self($console('debug'), $console('info'), $console('warn'), $console('error'));
    }

    public function debug(mixed ...$args): void
    {
        ($this->debug)(...$args);
    }

    public function info(mixed ...$args): void
    {
        ($this->info)(...$args);
    }

    public function warn(mixed ...$args): void
    {
        ($this->warn)(...$args);
    }

    public function error(mixed ...$args): void
    {
        ($this->error)(...$args);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof \Throwable => $value::class . ': ' . $value->getMessage(),
            is_scalar($value) => var_export($value, true),
            default => (string) json_encode($value),
        };
    }
}
