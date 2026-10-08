<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Timer;

/**
 * Port of packages/utils/upgrade/src/modules/timer/timer.ts (and the `Timer` interface of
 * types.ts). Times are epoch milliseconds, like `Date.now()`.
 *
 * PHP-only: the clock is injectable (upstream's tests use Jest fake timers instead).
 */
final class Timer
{
    /** @var array{start: int, end: int|null} */
    private array $interval;

    /** @var \Closure(): int */
    private \Closure $now;

    /** @param (\Closure(): int)|null $now */
    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->reset();
    }

    public static function timerFactory(?\Closure $now = null): self
    {
        return new self($now);
    }

    public function elapsedMs(): int
    {
        ['start' => $start, 'end' => $end] = $this->interval;

        return $end !== null ? $end - $start : ($this->now)() - $start;
    }

    public function end(): ?int
    {
        return $this->interval['end'];
    }

    public function start(): int
    {
        return $this->interval['start'];
    }

    public function stop(): int
    {
        $this->interval['end'] = ($this->now)();

        return $this->elapsedMs();
    }

    public function reset(): self
    {
        $this->interval = ['start' => ($this->now)(), 'end' => null];

        return $this;
    }
}
