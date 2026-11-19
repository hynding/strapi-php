<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use Strapi\Core\Services\Cron;
use Strapi\Core\Tests\BootedAppTestCase;

/**
 * `runDue()`, the entry point of `strapi cron:run` from the system crontab. Each new Cron instance
 * here is one call of the command (a new process); persistState() keeps what one call learns for
 * the next.
 */
final class CronTest extends BootedAppTestCase
{
    private string $dir = '';

    /** @var list<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-cron-' . bin2hex(random_bytes(6));
        $this->fired = [];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-03-02 {$time}:00", new \DateTimeZone('UTC'));
    }

    /** One `cron:run` call: a new process with the job registered at boot. */
    private function call(string $time, mixed $schedule = '0 12 * * *', bool $persist = true): int
    {
        $cron = Cron::createCronService(self::strapi());
        if ($persist) {
            $cron->persistState($this->dir);
        }
        $cron->add(['job' => [
            'task' => function (mixed $strapi, \DateTimeInterface $fireDate): void {
                $this->fired[] = $fireDate->format('H:i');
            },
            'options' => is_string($schedule) ? ['rule' => $schedule, 'tz' => 'UTC'] : $schedule,
        ]]);

        return $cron->runDue(self::at($time));
    }

    public function testWithoutStateEachCallLooksBackOneMinuteOnly(): void
    {
        // a crontab every 5 minutes misses a job due at 12:00, and two calls in one minute run it twice
        self::assertSame(0, $this->call('12:05', persist: false));
        self::assertSame(1, $this->call('12:00', persist: false));
        self::assertSame(1, $this->call('12:00', persist: false));
    }

    public function testAJobDueBetweenTwoCallsRunsOnceAtTheNextCall(): void
    {
        self::assertSame(0, $this->call('11:55'));
        self::assertSame(1, $this->call('12:05'));
        self::assertSame(['12:00'], $this->fired);

        self::assertSame(0, $this->call('12:10'));
        self::assertSame(['12:00'], $this->fired);
    }

    public function testTwoCallsInOneMinuteRunAJobOnce(): void
    {
        self::assertSame(1, $this->call('12:00', '* * * * *'));
        self::assertSame(0, $this->call('12:00', '* * * * *'));
        self::assertSame(1, $this->call('12:01', '* * * * *'));
    }

    public function testMissedRunsCoalesceIntoOne(): void
    {
        self::assertSame(1, $this->call('09:00', '0 * * * *'));
        // the crontab stopped for three hours
        self::assertSame(1, $this->call('12:30', '0 * * * *'));
        self::assertSame(['09:00', '10:00'], $this->fired);
    }

    public function testAOneShotJobRunsOnceAcrossCalls(): void
    {
        $date = self::at('12:00');

        self::assertSame(0, $this->call('11:59', $date));
        self::assertSame(1, $this->call('12:01', $date));
        self::assertSame(0, $this->call('12:02', $date));
        self::assertSame(['12:00'], $this->fired);
    }

    public function testAJobKeepsItsLocalTimeAcrossADaylightSavingChange(): void
    {
        // no `tz`: the server's time zone, where 09:00 is +01:00 before the change and +02:00 after
        $default = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $call = function (string $at): int {
                $cron = Cron::createCronService(self::strapi())->persistState($this->dir);
                $cron->add(['job' => [
                    'task' => function (mixed $strapi, \DateTimeInterface $fireDate): void {
                        $this->fired[] = $fireDate->format('Y-m-d H:i');
                    },
                    'options' => ['rule' => '0 9 * * *'],
                ]]);

                return $cron->runDue(new \DateTimeImmutable($at));
            };

            self::assertSame(1, $call('2026-03-28 09:00'));
            // clocks went forward on 2026-03-29 at 02:00
            self::assertSame(1, $call('2026-03-29 09:00'));
            self::assertSame(1, $call('2026-03-30 09:00'));
            self::assertSame(['2026-03-28 09:00', '2026-03-29 09:00', '2026-03-30 09:00'], $this->fired);
        } finally {
            date_default_timezone_set($default);
        }
    }

    public function testAStateDirectoryThatCannotBeWrittenRunsJobsWithoutState(): void
    {
        // under a file: the directory cannot be created
        touch($this->dir);
        try {
            $cron = Cron::createCronService(self::strapi())->persistState($this->dir . '/cron');
            $cron->add(['job' => [
                'task' => function (): void {
                    $this->fired[] = 'ran';
                },
                'options' => ['rule' => '0 12 * * *', 'tz' => 'UTC'],
            ]]);

            self::assertSame(1, $cron->runDue(self::at('12:00')));
            self::assertSame(['ran'], $this->fired);
        } finally {
            unlink($this->dir);
        }
    }

    public function testAJobRunningInAnotherCallIsSkipped(): void
    {
        $this->call('11:55');

        // another call is running the job: it holds the job's state, where it recorded the run
        $handle = fopen($this->dir . '/' . hash('sha256', 'job|1') . '.job', 'c+');
        self::assertNotFalse($handle);
        flock($handle, LOCK_EX);
        fwrite($handle, self::at('12:05')->format(DATE_ATOM));
        fflush($handle);

        try {
            self::assertSame(0, $this->call('12:05'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // and once it is done, the run is not repeated
        self::assertSame(0, $this->call('12:06'));
        self::assertSame([], $this->fired);
    }
}
