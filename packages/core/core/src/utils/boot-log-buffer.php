<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Monolog\Handler\Handler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * PHP port: holds the records logged while Strapi boots, for the SAPIs that boot on every request
 * ({@see Sapi::isPerRequest()}). Upstream logs its startup messages (config warnings, plugin
 * notices) once per process; under PHP-FPM that would be once per request, in the error log. At
 * the end of bootstrap a settled boot (the inputs of a bootstrap that already completed, so the
 * same messages, already logged then) discards them; any other boot logs them. A settled boot's
 * shutdown is held and discarded the same way. An error is never held: it logs at once, after
 * what was held before it.
 */
final class BootLogBuffer extends Handler
{
    /** @var list<LogRecord> */
    private array $held = [];

    private bool $holding = true;

    /** @param list<HandlerInterface> $handlers */
    public function __construct(private readonly array $handlers)
    {
    }

    public function isHandling(LogRecord $record): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record)) {
                return true;
            }
        }

        return false;
    }

    public function handle(LogRecord $record): bool
    {
        if ($this->holding && $record->level->isLowerThan(Level::Error)) {
            if ($this->isHandling($record)) {
                $this->held[] = $record;
            }

            return false;
        }

        if ($this->holding) {
            $this->release(false);
        }
        $this->forward($record);

        return false;
    }

    /** Holds again (a settled boot's shutdown, every request's), until the next release(). */
    public function hold(): void
    {
        $this->holding = true;
    }

    /** Stops holding; logs what was held, or drops it (`$discard`, a settled boot). */
    public function release(bool $discard): void
    {
        $held = $this->held;
        $this->held = [];
        $this->holding = false;

        if (!$discard) {
            foreach ($held as $record) {
                $this->forward($record);
            }
        }
    }

    public function close(): void
    {
        if ($this->holding) {
            $this->release(false);
        }
        foreach ($this->handlers as $handler) {
            $handler->close();
        }
    }

    /** @return list<HandlerInterface> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    private function forward(LogRecord $record): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record) && $handler->handle($record)) {
                return; // a handler that stops bubbling stops the others, as in Monolog\Logger
            }
        }
    }
}
