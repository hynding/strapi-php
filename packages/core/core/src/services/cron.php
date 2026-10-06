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

    /** node-schedule RecurrenceSpecObjLit → cron (second minute hour date month dayOfWeek). @param array<string, mixed> $spec */
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
     * Run every job due as of $now (the FPM entry point: `strapi cron:run`). A job is due when
     * its schedule matched at any minute since its last run (or since $now's minute for a first run).
     */
    public function runDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $ran = 0;

        foreach ($this->jobsSpecs as $index => $spec) {
            if ($spec['paused'] && $this->running === false && PHP_SAPI !== 'cli') {
                continue;
            }
            if ($spec['done']) {
                continue;
            }
            if ($spec['start'] !== null && $now < $spec['start']) {
                continue;
            }
            if ($spec['end'] !== null && $now > $spec['end']) {
                $this->jobsSpecs[$index]['done'] = true;
                continue;
            }

            $fireDate = null;
            if ($spec['oneShot'] !== null) {
                if ($now >= $spec['oneShot']) {
                    $fireDate = $spec['oneShot'];
                    $this->jobsSpecs[$index]['done'] = true;
                }
            } elseif ($spec['expression'] !== null) {
                $since = $spec['lastRun'] ?? $now->modify('-1 minute');
                try {
                    $next = \DateTimeImmutable::createFromMutable($spec['expression']->getNextRunDate($since, 0, false, $spec['tz']));
                } catch (\Throwable) {
                    continue;
                }
                if ($next <= $now) {
                    $fireDate = $next;
                }
            }

            if ($fireDate === null) {
                continue;
            }

            $this->jobsSpecs[$index]['lastRun'] = $now;
            $ran++;
            try {
                ($spec['fn'])($this->strapi, $fireDate);
            } catch (\Throwable $error) {
                $this->strapi->log()->error("Cron job \"{$spec['label']}\" failed", ['exception' => $error]);
            }
        }

        return $ran;
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
