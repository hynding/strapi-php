<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\EventHub;

/** strapi.eventHub — mirrors Modules.EventHub.EventHub. */
interface EventHub
{
    /**
     * @param callable(mixed ...$args): void $listener
     * @return callable(): void unsubscribe
     */
    public function on(string $event, callable $listener): callable;

    public function off(string $event, callable $listener): void;

    public function once(string $event, callable $listener): void;

    public function emit(string $event, mixed ...$args): void;

    /** @param callable(string $event, mixed ...$args): void $subscriber */
    public function subscribe(callable $subscriber): callable;

    public function removeAllListeners(?string $event = null): void;
}
