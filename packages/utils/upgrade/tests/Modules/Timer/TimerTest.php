<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Timer;

use Strapi\Upgrade\Modules\Timer\Timer;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/timer/__tests__/timer.test.ts (an injected clock instead of fake timers). */
final class TimerTest extends TestCase
{
    private const FIXED_NOW = 1_700_000_000_000;

    private int $now = self::FIXED_NOW;

    private function timer(): Timer
    {
        return Timer::timerFactory(fn (): int => $this->now);
    }

    public function testDefaults(): void
    {
        $timer = $this->timer();

        self::assertInstanceOf(Timer::class, $timer);
        self::assertSame(self::FIXED_NOW, $timer->start());
        self::assertNull($timer->end());
        self::assertSame(0, $timer->elapsedMs());
    }

    public function testElapsedTimeIsDynamic(): void
    {
        $timer = $this->timer();
        $this->now += 250;

        self::assertSame(250, $timer->elapsedMs());
    }

    public function testStopFreezesTheTimer(): void
    {
        $timer = $this->timer();
        $this->now += 42;
        $timer->stop();
        $this->now += 100;

        self::assertSame(self::FIXED_NOW, $timer->start());
        self::assertSame(self::FIXED_NOW + 42, $timer->end());
        self::assertSame(42, $timer->elapsedMs());
    }

    public function testResetReinitializesTheTimer(): void
    {
        $timer = $this->timer();
        $this->now += 42;
        $timer->stop();
        $timer->reset();

        self::assertSame(self::FIXED_NOW + 42, $timer->start());
        self::assertNull($timer->end());
        self::assertSame(0, $timer->elapsedMs());
    }

    public function testDefaultClockIsEpochMilliseconds(): void
    {
        self::assertEqualsWithDelta(microtime(true) * 1000, Timer::timerFactory()->start(), 1000);
    }
}
