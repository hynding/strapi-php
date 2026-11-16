<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\Utils\Utils;

/** `ensureWeeklyStoredCronSchedule`: when the weekly schedule is (re)generated. */
final class WeeklyMetricsTest extends AppTestCase
{
    private const array KEY = ['type' => 'plugin', 'name' => 'upload', 'key' => 'metrics'];

    /** @param array<string, mixed>|null $value */
    private static function store(?array $value): void
    {
        $value === null ? self::strapi()->store()->delete(self::KEY) : self::strapi()->store()->set([...self::KEY, 'value' => $value]);
    }

    private static function schedule(): string
    {
        return Utils::getService('weeklyMetrics', self::strapi())->ensureWeeklyStoredCronSchedule();
    }

    public function testGeneratesAndStoresAScheduleWhenThereIsNone(): void
    {
        self::store(null);

        $schedule = self::schedule();

        self::assertMatchesRegularExpression('/^\d+ \d+ \d+ \* \* \d$/', $schedule);
        self::assertSame(['weeklySchedule' => $schedule], self::strapi()->store()->get(self::KEY));
    }

    public function testKeepsAStoredScheduleThatHasNotRunYet(): void
    {
        // every PHP-FPM request boots: a schedule regenerated on each boot would never come due
        self::store(['weeklySchedule' => '1 2 3 * * 4']);

        self::assertSame('1 2 3 * * 4', self::schedule());
        self::assertSame(['weeklySchedule' => '1 2 3 * * 4'], self::strapi()->store()->get(self::KEY));
    }

    public function testKeepsTheScheduleWithinAWeekOfTheLastSend(): void
    {
        $lastWeeklyUpdate = (int) floor(microtime(true) * 1000) - 24 * 60 * 60 * 1000;
        self::store(['weeklySchedule' => '1 2 3 * * 4', 'lastWeeklyUpdate' => $lastWeeklyUpdate]);

        self::assertSame('1 2 3 * * 4', self::schedule());
    }

    public function testReschedulesWhenTheLastSendIsOlderThanAWeek(): void
    {
        $lastWeeklyUpdate = (int) floor(microtime(true) * 1000) - 8 * 24 * 60 * 60 * 1000;
        self::store(['weeklySchedule' => '1 2 3 * * 4', 'lastWeeklyUpdate' => $lastWeeklyUpdate]);

        $schedule = self::schedule();

        self::assertNotSame('1 2 3 * * 4', $schedule);
        self::assertSame(['weeklySchedule' => $schedule, 'lastWeeklyUpdate' => $lastWeeklyUpdate], self::strapi()->store()->get(self::KEY));
    }
}
