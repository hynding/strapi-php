<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Stream;

/**
 * Not an upstream file: Node's `EventEmitter` API as the engine's progress stream uses it
 * (`progress.stream.on('stage::progress', …)` / `emit()`).
 */
final class EventEmitter
{
    /** @var array<string, list<array{callable(mixed): mixed, bool}>> */
    private array $listeners = [];

    /** @param callable(mixed): mixed $listener */
    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = [$listener, false];

        return $this;
    }

    /** @param callable(mixed): mixed $listener */
    public function once(string $event, callable $listener): self
    {
        $this->listeners[$event][] = [$listener, true];

        return $this;
    }

    public function removeAllListeners(?string $event = null): self
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    public function emit(string $event, mixed $payload = null): bool
    {
        $listeners = $this->listeners[$event] ?? [];
        if ($listeners === []) {
            return false;
        }

        $this->listeners[$event] = array_values(array_filter($listeners, static fn (array $l): bool => !$l[1]));
        foreach ($listeners as [$listener]) {
            $listener($payload);
        }

        return true;
    }

    public function listenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []);
    }
}
