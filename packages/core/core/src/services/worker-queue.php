<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Psr\Log\LoggerInterface;

/**
 * Port of packages/core/core/src/services/worker-queue.ts. PHP is synchronous, so every enqueued
 * payload runs immediately (concurrency is kept for API parity but has no effect).
 *
 * @template TPayload
 */
final class WorkerQueue
{
    /** @var \Closure(TPayload): mixed */
    private \Closure $worker;

    public int $running = 0;

    /** @var list<TPayload> */
    public array $queue = [];

    public function __construct(private readonly LoggerInterface $logger, public readonly int $concurrency = 5)
    {
        $this->worker = static fn (mixed $payload): mixed => null;
    }

    /** @param callable(TPayload): mixed $worker */
    public function subscribe(callable $worker): void
    {
        $this->worker = $worker(...);
    }

    /** @param TPayload $payload */
    public function enqueue(mixed $payload): void
    {
        $this->running += 1;
        $this->execute($payload);
    }

    public function pop(): void
    {
        $payload = array_pop($this->queue);
        if ($payload !== null) {
            $this->execute($payload);
        } else {
            $this->running -= 1;
        }
    }

    /** @param TPayload $payload */
    public function execute(mixed $payload): void
    {
        try {
            ($this->worker)($payload);
        } catch (\Throwable $error) {
            $this->logger->error($error->getMessage(), ['exception' => $error]);
        } finally {
            $this->pop();
        }
    }
}
