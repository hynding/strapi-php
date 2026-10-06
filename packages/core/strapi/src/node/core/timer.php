<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

/** Port of packages/core/strapi/src/node/core/timer.ts (`getTimer`, `prettyTime`). */
final class Timer
{
    /** @var array<string, float> */
    private array $timings = [];

    /** @var array<string, float> */
    private array $startTimes = [];

    public static function getTimer(): self
    {
        return new self();
    }

    public function start(string $name): void
    {
        if (isset($this->startTimes[$name])) {
            throw new \RuntimeException("Timer \"{$name}\" already started, cannot overwrite");
        }
        $this->startTimes[$name] = microtime(true) * 1000;
    }

    /** @return float elapsed milliseconds */
    public function end(string $name): float
    {
        if (!isset($this->startTimes[$name])) {
            throw new \RuntimeException("Timer \"{$name}\" never started, cannot end");
        }
        $this->timings[$name] = microtime(true) * 1000 - $this->startTimes[$name];

        return $this->timings[$name];
    }

    /** @return array<string, float> */
    public function getTimings(): array
    {
        return $this->timings;
    }

    public static function prettyTime(float $timeInMs): string
    {
        return ((int) ceil($timeInMs)) . 'ms';
    }
}
