<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Types\Modules\EventHub\EventHub as EventHubContract;

/** Port of packages/core/core/src/services/event-hub.ts: Strapi's event control center. */
final class EventHub implements EventHubContract
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var list<callable> */
    private array $subscribers = [];

    /** @var \Closure */
    private \Closure $defaultSubscriber;

    public function __construct()
    {
        // Default subscriber to easily add listeners with the on() method
        $this->defaultSubscriber = function (string $eventName, mixed ...$args): void {
            foreach ($this->listeners[$eventName] ?? [] as $listener) {
                $listener(...$args);
            }
        };
        $this->subscribers = [$this->defaultSubscriber];
    }

    public static function createEventHub(): self
    {
        return new self();
    }

    public function emit(string $event, mixed ...$args): void
    {
        foreach ($this->subscribers as $subscriber) {
            $subscriber($event, ...$args);
        }
    }

    public function subscribe(callable $subscriber): callable
    {
        $this->subscribers[] = $subscriber;

        return fn () => $this->unsubscribe($subscriber);
    }

    public function unsubscribe(callable $subscriber): void
    {
        foreach ($this->subscribers as $index => $registered) {
            if ($registered === $subscriber) {
                array_splice($this->subscribers, $index, 1);

                return;
            }
        }
    }

    public function on(string $event, callable $listener): callable
    {
        $this->listeners[$event][] = $listener;

        return fn () => $this->off($event, $listener);
    }

    public function off(string $event, callable $listener): void
    {
        foreach ($this->listeners[$event] ?? [] as $index => $registered) {
            if ($registered === $listener) {
                array_splice($this->listeners[$event], $index, 1);

                return;
            }
        }
    }

    public function once(string $event, callable $listener): void
    {
        $wrapper = null;
        $wrapper = function (mixed ...$args) use ($event, $listener, &$wrapper): void {
            $this->off($event, $wrapper);
            $listener(...$args);
        };
        $this->on($event, $wrapper);
    }

    public function destroy(): static
    {
        $this->removeAllListeners();
        $this->removeAllSubscribers();

        return $this;
    }

    public function removeListener(string $event, callable $listener): void
    {
        $this->off($event, $listener);
    }

    public function removeAllListeners(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }

    public function removeAllSubscribers(): static
    {
        $this->subscribers = [];

        return $this;
    }

    public function addListener(string $event, callable $listener): callable
    {
        return $this->on($event, $listener);
    }
}
