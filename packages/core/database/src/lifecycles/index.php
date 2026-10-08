<?php

declare(strict_types=1);

namespace Strapi\Database\Lifecycles;

use Strapi\Database\Database;
use Strapi\Database\Lifecycles\Subscribers\ModelsLifecycles;
use Strapi\Database\Lifecycles\Subscribers\Timestamps;

/**
 * Port of packages/core/database/src/lifecycles/index.ts (`createLifecyclesProvider`).
 *
 * A subscriber is either a `callable(Event): void` or a map
 * `['models' => [uid, ...]?, '<action>' => callable(Event): void, ...]`.
 * `run()` returns a states map (keyed by subscriber index) that is passed to the matching
 * `after*` call so each subscriber keeps its state between the two events.
 */
final class Lifecycles
{
    public const ACTIONS = [
        'beforeCreate', 'afterCreate',
        'beforeFindOne', 'afterFindOne',
        'beforeFindMany', 'afterFindMany',
        'beforeCount', 'afterCount',
        'beforeCreateMany', 'afterCreateMany',
        'beforeUpdate', 'afterUpdate',
        'beforeUpdateMany', 'afterUpdateMany',
        'beforeDelete', 'afterDelete',
        'beforeDeleteMany', 'afterDeleteMany',
    ];

    /** @var array<int, callable|array<string, mixed>> */
    private array $subscribers = [];

    private int $counter = 0;

    private bool $disabled = false;

    public function __construct(private readonly Database $db)
    {
        $this->subscribers[$this->counter++] = Timestamps::subscriber();
        $this->subscribers[$this->counter++] = new ModelsLifecycles();
    }

    /**
     * @param callable|array<string, mixed> $subscriber
     *
     * @return callable(): void unsubscribe
     */
    public function subscribe(callable|array $subscriber): callable
    {
        $id = $this->counter++;
        $this->subscribers[$id] = $subscriber;

        return function () use ($id): void {
            unset($this->subscribers[$id]);
        };
    }

    public function clear(): void
    {
        $this->subscribers = [];
    }

    public function disable(): void
    {
        $this->disabled = true;
    }

    public function enable(): void
    {
        $this->disabled = false;
    }

    /**
     * @param array{params?: array<string, mixed>, result?: mixed} $properties
     * @param array<string, mixed> $state
     */
    public function createEvent(string $action, string $uid, array $properties, array $state = []): Event
    {
        $model = $this->db->metadata->get($uid);

        return new Event(
            $action,
            $model,
            $properties['params'] ?? [],
            $state,
            $properties['result'] ?? null,
            array_key_exists('result', $properties),
        );
    }

    /**
     * Runs every subscriber for the action. `$properties['params']` is passed by reference so that
     * subscribers (the timestamps one) can mutate the params the caller continues with.
     *
     * @param array{params: array<string, mixed>, result?: mixed} $properties
     * @param array<int, array<string, mixed>> $states
     *
     * @return array<int, array<string, mixed>>
     */
    public function run(string $action, string $uid, array &$properties, array $states = []): array
    {
        if ($this->disabled) {
            return $states;
        }

        foreach ($this->subscribers as $id => $subscriber) {
            if (is_callable($subscriber)) {
                $state = $states[$id] ?? [];
                $event = $this->createEvent($action, $uid, $properties, $state);
                $subscriber($event);
                $properties['params'] = $event->params;
                if ($event->state !== []) {
                    $states[$id] = $event->state;
                }
                continue;
            }

            $hasAction = isset($subscriber[$action]) && is_callable($subscriber[$action]);
            $hasModel = !isset($subscriber['models']) || in_array($uid, (array) $subscriber['models'], true);

            if ($hasAction && $hasModel) {
                $state = $states[$id] ?? [];
                $event = $this->createEvent($action, $uid, $properties, $state);
                $subscriber[$action]($event);
                $properties['params'] = $event->params;
                if ($event->state !== []) {
                    $states[$id] = $event->state;
                }
            }
        }

        return $states;
    }
}
