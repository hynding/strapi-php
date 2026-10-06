<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Psr\Log\LoggerInterface;
use Strapi\Core\Utils\Fetch;
use Strapi\Types\Modules\EventHub\EventHub;

/**
 * Port of packages/core/core/src/services/webhook-runner.ts: fires registered webhooks when their
 * events are emitted on the event hub (POST with `X-Strapi-Event`, `Content-Type: application/json`
 * and the `{ event, createdAt, ...info }` payload, 10 s timeout).
 *
 * @phpstan-import-type Webhook from WebhookStore
 */
final class WebhookRunner
{
    /** @var array{defaultHeaders: array<string, string>} */
    private array $config;

    /** @var array<string, list<Webhook>> */
    private array $webhooksMap = [];

    /** @var array<string, \Closure> */
    private array $listeners = [];

    /** @var WorkerQueue<array{event: string, info: array<string, mixed>}> */
    private WorkerQueue $queue;

    /** @var \Closure(string, array<string, mixed>): array<string, mixed> */
    private \Closure $fetch;

    /**
     * @param array<string, mixed> $configuration `server.webhooks`
     * @param callable(string, array<string, mixed>): array<string, mixed> $fetch
     */
    public function __construct(
        private readonly EventHub $eventHub,
        private readonly LoggerInterface $logger,
        array $configuration,
        callable $fetch,
    ) {
        $this->config = ['defaultHeaders' => [], ...$configuration];
        if (!is_array($this->config['defaultHeaders'])) {
            $this->config['defaultHeaders'] = [];
        }
        $this->fetch = $fetch(...);

        $this->queue = new WorkerQueue($logger, 5);
        $this->queue->subscribe($this->executeListener(...));
    }

    /**
     * @param array{eventHub: EventHub, logger: LoggerInterface, configuration?: array<string, mixed>, fetch: callable} $opts
     */
    public static function createWebhookRunner(array $opts): self
    {
        return new self($opts['eventHub'], $opts['logger'], $opts['configuration'] ?? [], $opts['fetch']);
    }

    public function deleteListener(string $event): void
    {
        $fn = $this->listeners[$event] ?? null;

        if ($fn !== null) {
            $this->eventHub->off($event, $fn);
            unset($this->listeners[$event]);
        }
    }

    public function createListener(string $event): void
    {
        if (isset($this->listeners[$event])) {
            $this->logger->error("The webhook runner is already listening for the event '{$event}'. Did you mean to call .register() ?");
        }

        $listen = function (mixed $info = []) use ($event): void {
            $this->queue->enqueue(['event' => $event, 'info' => is_array($info) ? $info : []]);
        };

        $this->listeners[$event] = $listen;
        $this->eventHub->on($event, $listen);
    }

    /** @param array{event: string, info: array<string, mixed>} $payload */
    public function executeListener(array $payload): void
    {
        ['event' => $event, 'info' => $info] = $payload;
        $webhooks = $this->webhooksMap[$event] ?? [];

        foreach ($webhooks as $webhook) {
            if (($webhook['isEnabled'] ?? false) !== true) {
                continue;
            }
            try {
                $this->run($webhook, $event, $info);
            } catch (\Throwable $error) {
                $this->logger->error('Error running webhook');
                $this->logger->error($error->getMessage(), ['exception' => $error]);
            }
        }
    }

    /**
     * @param Webhook $webhook
     * @param array<string, mixed> $info
     * @return array{statusCode: int, message?: string}
     */
    public function run(array $webhook, string $event, array $info = []): array
    {
        $url = $webhook['url'];
        $headers = $webhook['headers'] ?? [];

        try {
            $res = ($this->fetch)($url, [
                'method' => 'post',
                'body' => json_encode(['event' => $event, 'createdAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'), ...$info], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'headers' => [
                    ...$this->config['defaultHeaders'],
                    ...$headers,
                    'X-Strapi-Event' => $event,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            if ($res['ok']) {
                return ['statusCode' => $res['status']];
            }

            return ['statusCode' => $res['status'], 'message' => $res['body']];
        } catch (\Throwable $err) {
            return ['statusCode' => 500, 'message' => $err->getMessage()];
        }
    }

    /** @param Webhook $webhook */
    public function add(array $webhook): void
    {
        foreach ($webhook['events'] ?? [] as $event) {
            if (isset($this->webhooksMap[$event])) {
                $this->webhooksMap[$event][] = $webhook;
            } else {
                $this->webhooksMap[$event] = [$webhook];
                $this->createListener($event);
            }
        }
    }

    /** @param Webhook $webhook */
    public function update(array $webhook): void
    {
        $this->remove($webhook);
        $this->add($webhook);
    }

    /** @param Webhook $webhook */
    public function remove(array $webhook): void
    {
        foreach ($this->webhooksMap as $event => $webhooks) {
            $filtered = array_values(array_filter($webhooks, static fn (array $value): bool => ($value['id'] ?? null) !== ($webhook['id'] ?? null)));

            // Cleanup hanging listeners
            if ($filtered === []) {
                unset($this->webhooksMap[$event]);
                $this->deleteListener($event);
            } else {
                $this->webhooksMap[$event] = $filtered;
            }
        }
    }
}
