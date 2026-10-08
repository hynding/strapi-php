<?php

declare(strict_types=1);

namespace Strapi\Openapi\Utils\Timer;

/** Port of packages/core/openapi/src/utils/timer/timer.ts (milliseconds, like `Date.now()`). */
final class Timer
{
    private ?int $startTime = null;

    private ?int $endTime = null;

    private ?int $elapsedTime = null;

    public function start(): int
    {
        if ($this->startTime !== null) {
            throw new \LogicException('Timer is already started. Use `reset()` to reset the timer before starting it again.');
        }

        $this->startTime = self::now();

        $this->endTime = null;
        $this->elapsedTime = null;

        return $this->startTime;
    }

    /** @return array{startTime: int, endTime: int, elapsedTime: int} */
    public function stop(): array
    {
        if ($this->startTime === null) {
            throw new \LogicException('Timer is not started. Use `start()` to start the timer before stopping it.');
        }

        $this->endTime = self::now();
        $this->elapsedTime = $this->endTime - $this->startTime;

        return ['startTime' => $this->startTime, 'endTime' => $this->endTime, 'elapsedTime' => $this->elapsedTime];
    }

    public function reset(): void
    {
        $this->startTime = null;
        $this->endTime = null;
        $this->elapsedTime = null;
    }

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
