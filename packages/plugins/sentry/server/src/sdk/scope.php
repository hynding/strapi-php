<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Sdk;

/**
 * Not an upstream file (part of the `@sentry/node` replacement): the subset of `@sentry/core`'s
 * `Scope` that Strapi code uses to enrich events — tags, extra, contexts, user, level,
 * fingerprint, transaction name, breadcrumbs and event processors. `applyToEvent()` merges the
 * scope into an event the way `Scope.applyToEvent` does (event data wins over scope data).
 */
final class Scope
{
    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private ?string $level = null;

    /** @var list<string>|null */
    private ?array $fingerprint = null;

    private ?string $transactionName = null;

    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];

    /** @var list<callable(array<string, mixed>, array<string, mixed>): (array<string, mixed>|null)> */
    private array $eventProcessors = [];

    public function __construct(private readonly int $maxBreadcrumbs = 100)
    {
    }

    public function clone(): self
    {
        return clone $this;
    }

    public function setTag(string $key, mixed $value): static
    {
        $this->tags[$key] = self::stringValue($value);

        return $this;
    }

    /** @param array<string, mixed> $tags */
    public function setTags(array $tags): static
    {
        foreach ($tags as $key => $value) {
            $this->setTag((string) $key, $value);
        }

        return $this;
    }

    public function setExtra(string $key, mixed $value): static
    {
        $this->extra[$key] = $value;

        return $this;
    }

    /** @param array<string, mixed> $extras */
    public function setExtras(array $extras): static
    {
        foreach ($extras as $key => $value) {
            $this->extra[(string) $key] = $value;
        }

        return $this;
    }

    /** @param array<string, mixed>|null $context null removes the context */
    public function setContext(string $key, ?array $context): static
    {
        if ($context === null) {
            unset($this->contexts[$key]);
        } else {
            $this->contexts[$key] = $context;
        }

        return $this;
    }

    /** @param array<string, mixed>|null $user `id`, `username`, `email`, `ip_address`... */
    public function setUser(?array $user): static
    {
        $this->user = $user;

        return $this;
    }

    /** `fatal`, `error`, `warning`, `log`, `info` or `debug` */
    public function setLevel(?string $level): static
    {
        $this->level = $level;

        return $this;
    }

    /** @param list<string>|null $fingerprint */
    public function setFingerprint(?array $fingerprint): static
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function setTransactionName(?string $name): static
    {
        $this->transactionName = $name;

        return $this;
    }

    /** @param array<string, mixed> $breadcrumb */
    public function addBreadcrumb(array $breadcrumb, ?int $maxBreadcrumbs = null): static
    {
        $max = $maxBreadcrumbs ?? $this->maxBreadcrumbs;
        if ($max <= 0) {
            return $this;
        }

        $this->breadcrumbs[] = ['timestamp' => microtime(true), ...$breadcrumb];
        $this->breadcrumbs = array_slice($this->breadcrumbs, -$max);

        return $this;
    }

    public function clearBreadcrumbs(): static
    {
        $this->breadcrumbs = [];

        return $this;
    }

    /** @param callable(array<string, mixed>, array<string, mixed>): (array<string, mixed>|null) $processor return null to drop the event */
    public function addEventProcessor(callable $processor): static
    {
        $this->eventProcessors[] = $processor;

        return $this;
    }

    public function clear(): static
    {
        $this->tags = [];
        $this->extra = [];
        $this->contexts = [];
        $this->user = null;
        $this->level = null;
        $this->fingerprint = null;
        $this->transactionName = null;
        $this->breadcrumbs = [];
        $this->eventProcessors = [];

        return $this;
    }

    /** @return array<string, string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return array<string, mixed> */
    public function getExtras(): array
    {
        return $this->extra;
    }

    /** @return array<string, array<string, mixed>> */
    public function getContexts(): array
    {
        return $this->contexts;
    }

    /** @return array<string, mixed>|null */
    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getLevel(): ?string
    {
        return $this->level;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $hint
     * @return array<string, mixed>|null null when an event processor dropped the event
     */
    public function applyToEvent(array $event, array $hint = []): ?array
    {
        if ($this->extra !== []) {
            $event['extra'] = [...$this->extra, ...($event['extra'] ?? [])];
        }
        if ($this->tags !== []) {
            $event['tags'] = [...$this->tags, ...($event['tags'] ?? [])];
        }
        if ($this->user !== null && $this->user !== []) {
            $event['user'] = [...$this->user, ...($event['user'] ?? [])];
        }
        if ($this->contexts !== []) {
            $event['contexts'] = [...$this->contexts, ...($event['contexts'] ?? [])];
        }
        if ($this->level !== null) {
            $event['level'] = $this->level;
        }
        if ($this->transactionName !== null) {
            $event['transaction'] = $this->transactionName;
        }
        if ($this->fingerprint !== null) {
            $event['fingerprint'] = [...($event['fingerprint'] ?? []), ...$this->fingerprint];
        }
        if ($this->breadcrumbs !== []) {
            $event['breadcrumbs'] = [...($event['breadcrumbs'] ?? []), ...$this->breadcrumbs];
        }

        foreach ($this->eventProcessors as $processor) {
            $processed = $processor($event, $hint);
            if ($processed === null) {
                return null;
            }
            $event = $processed;
        }

        return $event;
    }

    private static function stringValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
