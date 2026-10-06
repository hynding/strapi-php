<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\Cron;

/** strapi.cron — mirrors Modules.Cron.CronService. */
interface CronService
{
    /** @param array<string, callable|array{task: callable, options: string|array<string, mixed>}> $tasks */
    public function add(array $tasks): static;

    public function remove(string $name): static;

    public function start(): static;

    public function stop(): static;

    public function destroy(): static;

    /** @return list<array{name: string, expression: string, nextRun: ?\DateTimeImmutable}> */
    public function jobs(): array;

    /** Run every job that is due as of $now (used by `strapi cron:run` under FPM). */
    public function runDue(?\DateTimeImmutable $now = null): int;
}
