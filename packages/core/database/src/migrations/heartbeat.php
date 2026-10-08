<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

/**
 * Port of packages/core/database/src/migrations/heartbeat.ts: a time-throttled progress logger
 * for long-running migrations.
 *
 * TODO: upstream wires this into the document-id internal migration (not ported, see
 * internal-migrations/index.php); kept as a working helper so migrations can use it.
 */
final class Heartbeat
{
    private float $startedAt;

    private float $lastEmittedAt;

    /** @var callable(): float */
    private $now;

    /**
     * @param callable(string): void $log
     * @param callable(): float|null $now injectable clock (milliseconds) for tests
     */
    public function __construct(private $log, private readonly int $intervalMs = 60_000, ?callable $now = null)
    {
        $this->now = $now ?? static fn (): float => microtime(true) * 1000;
        $this->startedAt = ($this->now)();
        $this->lastEmittedAt = $this->startedAt;
    }

    /**
     * Emits `$buildMessage(elapsedSeconds)` at most once per interval.
     *
     * @param callable(int): string $buildMessage
     */
    public function tick(callable $buildMessage): void
    {
        $current = ($this->now)();
        if ($current - $this->lastEmittedAt < $this->intervalMs) {
            return;
        }

        ($this->log)($buildMessage((int) floor(($current - $this->startedAt) / 1000)));
        $this->lastEmittedAt = $current;
    }
}
