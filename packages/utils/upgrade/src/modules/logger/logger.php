<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Logger;

use Strapi\Upgrade\Modules\Format\Chalk;

/**
 * Port of packages/utils/upgrade/src/modules/logger/logger.ts. `console.log/info` write to
 * stdout and `console.warn/error` to stderr, arguments joined with a space.
 *
 * PHP-only: the two streams can be injected (upstream's tests replace `console.*`).
 *
 * @phpstan-import-type LoggerOptions from Types
 */
class Logger
{
    public bool $isDebug;

    public bool $isSilent;

    private int $nbErrorsCalls = 0;

    private int $nbWarningsCalls = 0;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param LoggerOptions $options
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(array $options = [], $stdout = null, $stderr = null)
    {
        // Set verbosity options
        $this->isDebug = $options['debug'] ?? false;
        $this->isSilent = $options['silent'] ?? false;

        $this->out = $stdout ?? \STDOUT;
        $this->err = $stderr ?? \STDERR;
    }

    /**
     * @param LoggerOptions $options
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public static function loggerFactory(array $options = [], $stdout = null, $stderr = null): self
    {
        return new self($options, $stdout, $stderr);
    }

    private function isNotSilent(): bool
    {
        return !$this->isSilent;
    }

    public function errors(): int
    {
        return $this->nbErrorsCalls;
    }

    public function warnings(): int
    {
        return $this->nbWarningsCalls;
    }

    /** @return resource|null */
    public function stdout()
    {
        return $this->isSilent ? null : $this->out;
    }

    /** @return resource|null */
    public function stderr()
    {
        return $this->isSilent ? null : $this->err;
    }

    public function setDebug(bool $debug): static
    {
        $this->isDebug = $debug;

        return $this;
    }

    public function setSilent(bool $silent): static
    {
        $this->isSilent = $silent;

        return $this;
    }

    public function debug(mixed ...$args): static
    {
        $isDebugEnabled = $this->isNotSilent() && $this->isDebug;

        if ($isDebugEnabled) {
            $this->write($this->out, Chalk::cyan("[DEBUG]\t[" . self::nowAsISO() . ']'), ...$args);
        }

        return $this;
    }

    public function error(mixed ...$args): static
    {
        ++$this->nbErrorsCalls;

        if ($this->isNotSilent()) {
            $this->write($this->err, Chalk::red("[ERROR]\t[" . self::nowAsISO() . ']'), ...$args);
        }

        return $this;
    }

    public function info(mixed ...$args): static
    {
        if ($this->isNotSilent()) {
            $this->write($this->out, Chalk::blue("[INFO]\t[" . self::nowAsISO() . ']'), ...$args);
        }

        return $this;
    }

    public function raw(mixed ...$args): static
    {
        if ($this->isNotSilent()) {
            $this->write($this->out, ...$args);
        }

        return $this;
    }

    public function warn(mixed ...$args): static
    {
        ++$this->nbWarningsCalls;

        if ($this->isNotSilent()) {
            $this->write($this->err, Chalk::yellow("[WARN]\t[" . self::nowAsISO() . ']'), ...$args);
        }

        return $this;
    }

    /** @param resource $stream */
    private function write($stream, mixed ...$args): void
    {
        fwrite($stream, implode(' ', array_map(Chalk::stringify(...), $args)) . PHP_EOL);
    }

    /** `new Date().toISOString()` */
    public static function nowAsISO(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
