<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Cron\CronExpression;
use Strapi\Core\Strapi;
use Strapi\Types\Modules\Cron\CronService;

/**
 * Port of packages/core/core/src/services/cron.ts on dragonmantank/cron-expression.
 *
 * `add()` accepts upstream's formats:
 *   - `'* * * * *' => fn (Strapi $strapi, \DateTimeInterface $fireDate) => ...`
 *   - `'myJob' => ['task' => fn, 'options' => '* * * * *' | ['rule' => '...', 'tz' => ..., 'start' => ..., 'end' => ...]]`
 * Six-field expressions (seconds first) are accepted: the seconds field is dropped, which keeps
 * the minute granularity cron-expression supports. A `rule` may also be a timestamp / DateTime
 * (one-shot job).
 *
 * Execution model: `start()` runs a blocking scheduler loop only from the CLI (`strapi cron:run --watch`
 * or `strapi start` in worker mode); under FPM/FrankenPHP call `runDue()` periodically
 * (`strapi cron:run`) — PHP has no background timers.
 *
 * @phpstan-type JobSpec array{name: string|null, label: string, fn: \Closure, expression: CronExpression|null, oneShot: \DateTimeImmutable|null, tz: string|null, start: \DateTimeImmutable|null, end: \DateTimeImmutable|null, lastRun: \DateTimeImmutable|null, done: bool, paused: bool}
 */
final class Cron implements CronService
{
    /** @var list<JobSpec> */
    private array $jobsSpecs = [];

    private bool $running = false;

    private ?string $stateDir = null;

    private bool $stateWarned = false;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createCronService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function add(array $tasks): static
    {
        foreach ($tasks as $taskExpression => $taskValue) {
            $taskExpression = (string) $taskExpression;

            if (is_callable($taskValue)) {
                $taskName = null;
                $fn = $taskValue(...);
                $options = $taskExpression;
            } elseif (is_array($taskValue) && isset($taskValue['task']) && is_callable($taskValue['task'])) {
                $taskName = $taskExpression;
                $fn = $taskValue['task'](...);
                $options = $taskValue['options'] ?? null;
            } else {
                throw new \RuntimeException("Could not schedule a cron job for \"{$taskExpression}\": no function found.");
            }

            $jobLabel = $taskName ?? $taskExpression;

            try {
                $this->jobsSpecs[] = [...self::parseSchedule($options), 'name' => $taskName, 'label' => $jobLabel, 'fn' => $fn, 'lastRun' => null, 'done' => false, 'paused' => !$this->running];
            } catch (\Throwable $error) {
                $this->strapi->log()->error("Could not schedule cron job \"{$jobLabel}\": invalid schedule", ['exception' => $error]);
            }
        }

        return $this;
    }

    /**
     * @return array{expression: CronExpression|null, oneShot: \DateTimeImmutable|null, tz: string|null, start: \DateTimeImmutable|null, end: \DateTimeImmutable|null}
     */
    private static function parseSchedule(mixed $options): array
    {
        $base = ['expression' => null, 'oneShot' => null, 'tz' => null, 'start' => null, 'end' => null];

        if (is_int($options) || is_float($options) || $options instanceof \DateTimeInterface) {
            return [...$base, 'oneShot' => self::toDate($options)];
        }

        if (is_string($options)) {
            return [...$base, 'expression' => self::toExpression($options)];
        }

        if (is_array($options)) {
            $tz = isset($options['tz']) ? (string) $options['tz'] : null;
            $start = isset($options['start']) ? self::toDate($options['start']) : null;
            $end = isset($options['end']) ? self::toDate($options['end']) : null;

            $rule = $options['rule'] ?? $options;
            if (is_int($rule) || is_float($rule) || $rule instanceof \DateTimeInterface) {
                return [...$base, 'oneShot' => self::toDate($rule), 'tz' => $tz, 'start' => $start, 'end' => $end];
            }
            if (is_string($rule)) {
                return [...$base, 'expression' => self::toExpression($rule), 'tz' => $tz, 'start' => $start, 'end' => $end];
            }
            if (is_array($rule)) {
                return [...$base, 'expression' => self::toExpression(self::recurrenceToCron($rule)), 'tz' => $tz, 'start' => $start, 'end' => $end];
            }
        }

        throw new \RuntimeException('Unsupported cron schedule');
    }

    private static function toDate(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || is_float($value)) {
            // JS timestamps are in milliseconds
            return new \DateTimeImmutable('@' . (int) floor($value / 1000));
        }

        return new \DateTimeImmutable((string) $value);
    }

    private static function toExpression(string $rule): CronExpression
    {
        $fields = array_values(array_filter(explode(' ', trim($rule)), static fn (string $f): bool => $f !== ''));
        if (count($fields) === 6) {
            array_shift($fields); // seconds are not supported: minute granularity
        }
        if (count($fields) === 7) {
            array_shift($fields);
            array_pop($fields); // year
        }

        return new CronExpression(implode(' ', $fields));
    }

    /**
     * node-schedule RecurrenceSpecObjLit → cron (second minute hour date month dayOfWeek).
     *
     * @param array<string, mixed> $spec
     */
    private static function recurrenceToCron(array $spec): string
    {
        $segment = static function (mixed $value, string $fallback = '*', int $offset = 0) use (&$segment): string {
            if ($value === null) {
                return $fallback;
            }
            if (is_int($value)) {
                return (string) ($value + $offset);
            }
            if (is_string($value)) {
                return $value;
            }
            if (is_array($value) && array_is_list($value)) {
                return implode(',', array_map(static fn (mixed $item): string => $segment($item, $fallback, $offset), $value));
            }
            if (is_array($value)) {
                $start = $value['start'] ?? $value['from'] ?? null;
                $end = $value['end'] ?? $value['to'] ?? null;
                if ($start !== null && $end !== null) {
                    $range = ($start + $offset) . '-' . ($end + $offset);

                    return isset($value['step']) ? "{$range}/{$value['step']}" : $range;
                }
            }

            return $fallback;
        };

        $second = $segment($spec['second'] ?? null, '0');
        $minute = $segment($spec['minute'] ?? null);
        $hour = $segment($spec['hour'] ?? null);
        $date = $segment($spec['date'] ?? null);
        $month = $segment($spec['month'] ?? null, '*', 1);
        $dayOfWeek = $segment($spec['dayOfWeek'] ?? null);

        return "{$second} {$minute} {$hour} {$date} {$month} {$dayOfWeek}";
    }

    public function remove(string $name): static
    {
        if ($name === '') {
            throw new \RuntimeException('You must provide a name to remove a cron job.');
        }
        $this->jobsSpecs = array_values(array_filter($this->jobsSpecs, static fn (array $spec): bool => $spec['name'] !== $name));

        return $this;
    }

    public function start(): static
    {
        foreach ($this->jobsSpecs as &$spec) {
            $spec['paused'] = false;
        }
        unset($spec);
        $this->running = true;

        return $this;
    }

    public function stop(): static
    {
        foreach ($this->jobsSpecs as &$spec) {
            $spec['paused'] = true;
        }
        unset($spec);
        $this->running = false;

        return $this;
    }

    public function destroy(): static
    {
        $this->stop();
        $this->jobsSpecs = [];

        return $this;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function jobs(): array
    {
        $out = [];
        foreach ($this->jobsSpecs as $spec) {
            $out[] = [
                'name' => $spec['name'] ?? $spec['label'],
                'expression' => $spec['expression'] !== null ? $spec['expression']->getExpression() ?? '' : ($spec['oneShot']?->format(DATE_ATOM) ?? ''),
                'nextRun' => $this->nextRun($spec),
            ];
        }

        return $out;
    }

    /** @param JobSpec $spec */
    private function nextRun(array $spec, ?\DateTimeImmutable $from = null): ?\DateTimeImmutable
    {
        if ($spec['done']) {
            return null;
        }
        if ($spec['oneShot'] !== null) {
            return $spec['oneShot'];
        }
        if ($spec['expression'] === null) {
            return null;
        }
        $from ??= new \DateTimeImmutable();

        try {
            return \DateTimeImmutable::createFromMutable($spec['expression']->getNextRunDate($from, 0, false, $spec['tz']));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * PHP port: keeps each job's last run in `$dir` (one file per job) instead of in memory.
     * `strapi cron:run` from the system crontab is a new process every time: from memory, each
     * call looked back one minute only, so a crontab every 5 or 15 minutes (common on shared
     * hosting) missed most jobs, two calls in one minute ran a job twice, a one-shot job ran at
     * every call after its date, and a job still running was started again by the next call.
     * With the state, a job is due when its schedule matched since its last run (missed runs
     * coalesce into one), and a job another process is running is skipped (`flock`).
     */
    public function persistState(string $dir): static
    {
        $this->stateDir = rtrim($dir, '/');

        return $this;
    }

    /**
     * Run every job due as of $now (the FPM entry point: `strapi cron:run`). A job is due when
     * its schedule matched at any minute since its last run (or since $now's minute for a first
     * run; with persistState(), since the previous call).
     */
    public function runDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $ran = 0;
        $lastCheck = $this->readLastCheck();
        $labels = [];

        foreach ($this->jobsSpecs as $index => $spec) {
            // the nth job with this label, so two unnamed jobs on one expression keep apart
            $labels[$spec['label']] = ($labels[$spec['label']] ?? 0) + 1;
            $state = $this->openJobState("{$spec['label']}|{$labels[$spec['label']]}");
            if ($state === false) {
                continue; // running in another process
            }

            try {
                $ran += $this->runIfDue($index, $spec, $now, $state, $lastCheck);
            } finally {
                if ($state !== null) {
                    flock($state, LOCK_UN);
                    fclose($state);
                }
            }
        }

        $this->writeLastCheck($now);

        return $ran;
    }

    /**
     * @param JobSpec $spec
     * @param resource|null $state the job's locked state file (persistState()), or null
     */
    private function runIfDue(int $index, array $spec, \DateTimeImmutable $now, $state, ?\DateTimeImmutable $lastCheck): int
    {
        $lastRun = $state !== null ? self::readDate((string) stream_get_contents($state)) : $spec['lastRun'];

        if ($spec['paused'] && $this->running === false && PHP_SAPI !== 'cli') {
            return 0;
        }
        if ($spec['done']) {
            return 0;
        }
        if ($spec['start'] !== null && $now < $spec['start']) {
            return 0;
        }
        if ($spec['end'] !== null && $now > $spec['end']) {
            $this->jobsSpecs[$index]['done'] = true;

            return 0;
        }

        $fireDate = null;
        if ($spec['oneShot'] !== null) {
            if ($lastRun !== null) {
                // ran in an earlier process
                $this->jobsSpecs[$index]['done'] = true;
            } elseif ($now >= $spec['oneShot']) {
                $fireDate = $spec['oneShot'];
                $this->jobsSpecs[$index]['done'] = true;
            }
        } elseif ($spec['expression'] !== null) {
            $since = $lastRun ?? $lastCheck ?? $now->modify('-1 minute');
            try {
                $next = \DateTimeImmutable::createFromMutable($spec['expression']->getNextRunDate($since, 0, false, $spec['tz']));
            } catch (\Throwable) {
                return 0;
            }
            if ($next <= $now) {
                $fireDate = $next;
            }
        }

        if ($fireDate === null) {
            return 0;
        }

        $this->jobsSpecs[$index]['lastRun'] = $now;
        if ($state !== null) {
            // recorded before the job runs: a job that dies is not run again for the same time
            ftruncate($state, 0);
            rewind($state);
            fwrite($state, $now->format(DATE_ATOM));
            fflush($state);
        }

        try {
            ($spec['fn'])($this->strapi, $fireDate);
        } catch (\Throwable $error) {
            $this->strapi->log()->error("Cron job \"{$spec['label']}\" failed", ['exception' => $error]);
        }

        return 1;
    }

    /**
     * The job's state file, locked (persistState()); null without persisted state, or when it
     * cannot be written (the job runs as without persistState(), with a warning); false when
     * another process holds it (the job is running there).
     *
     * @return resource|false|null
     */
    private function openJobState(string $key)
    {
        if ($this->stateDir === null) {
            return null;
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0o777, true) && !is_dir($this->stateDir)) {
            return $this->withoutState("{$this->stateDir} cannot be created");
        }

        $handle = @fopen($this->stateDir . '/' . hash('sha256', $key) . '.job', 'c+');
        if ($handle === false) {
            return $this->withoutState("the state of \"{$key}\" in {$this->stateDir} cannot be opened");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    private function withoutState(string $reason): null
    {
        if (!$this->stateWarned) {
            $this->stateWarned = true;
            $this->strapi->log()->warning("Cron jobs run without their last runs: {$reason}");
        }

        return null;
    }

    private function readLastCheck(): ?\DateTimeImmutable
    {
        return $this->stateDir === null ? null : self::readDate((string) @file_get_contents($this->stateDir . '/last-check'));
    }

    private function writeLastCheck(\DateTimeImmutable $now): void
    {
        if ($this->stateDir === null || !is_dir($this->stateDir)) {
            return;
        }
        $tmp = $this->stateDir . '/last-check.' . getmypid();
        if (@file_put_contents($tmp, $now->format(DATE_ATOM)) !== false) {
            @rename($tmp, $this->stateDir . '/last-check');
        }
    }

    private static function readDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        // in the server's time zone, as `now` is: a job without `tz` is scheduled in the time zone
        // of the date it starts from, and a stored offset (+01:00) does not follow daylight saving
        return $date === false ? null : $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Blocking scheduler loop for worker mode (CLI only): polls every $intervalSeconds.
     *
     * @param callable(): bool|null $shouldContinue
     */
    public function loop(int $intervalSeconds = 30, ?callable $shouldContinue = null): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('The cron scheduler loop can only run from the CLI; use `strapi cron:run` under FPM');
        }
        $this->start();
        while ($shouldContinue === null || $shouldContinue()) {
            $this->runDue();
            sleep($intervalSeconds);
        }
    }
}
