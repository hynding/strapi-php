<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\EventHub;

/** Port of packages/core/core/src/services/__tests__/event-hub.test.ts. */
final class EventHubTest extends TestCase
{
    public function testOnlyTriggersTheCallbackOnceWithOnce(): void
    {
        $hub = EventHub::createEventHub();
        $calls = [];

        $hub->once('my-event', static function (mixed ...$args) use (&$calls): void {
            $calls[] = $args;
        });

        $hub->emit('my-event', 1, 2, 3);
        $hub->emit('my-event');
        $hub->emit('my-event');

        self::assertSame([[1, 2, 3]], $calls);
    }

    public function testSubscribesAndUnsubscribesToAllEvents(): void
    {
        $hub = EventHub::createEventHub();
        $calls = [];
        $fn = static function (mixed ...$args) use (&$calls): void {
            $calls[] = $args;
        };

        $hub->subscribe($fn);
        $hub->emit('my-event', 1, 2, 3);
        $hub->emit('my-event', 4, 5, 6);
        $hub->emit('my-other-event');

        self::assertSame([['my-event', 1, 2, 3], ['my-event', 4, 5, 6], ['my-other-event']], $calls);

        // Unsubscribes with unsubscribe()
        $hub->unsubscribe($fn);
        $hub->emit('my-event');
        self::assertCount(3, $calls);

        // Unsubscribes with the returned function
        $unsubscribe2 = $hub->subscribe($fn);
        $hub->emit('my-event');
        self::assertCount(4, $calls);
        $unsubscribe2();
        $hub->emit('my-event');
        self::assertCount(4, $calls);

        // Avoid removing the wrong subscriber when unsubscribe is given a non-existing subscriber
        $unsubscribe3 = $hub->subscribe($fn);
        $hub->unsubscribe(static fn () => null);
        $hub->emit('my-event');
        self::assertCount(5, $calls);
        $unsubscribe3();
    }

    public function testAddsAndRemovesSimpleListeners(): void
    {
        $hub = EventHub::createEventHub();
        $calls = [];
        $fn = static function (mixed ...$args) use (&$calls): void {
            $calls[] = $args;
        };

        $hub->on('my-event', $fn);
        $hub->emit('my-event', 1, 2, 3);
        self::assertSame([[1, 2, 3]], $calls);

        $hub->off('my-event', $fn);
        $hub->emit('my-event');
        self::assertCount(1, $calls);

        $off2 = $hub->on('my-event', $fn);
        $hub->emit('my-event', 1, 2, 3);
        self::assertCount(2, $calls);
        $off2();
        $hub->emit('my-event');
        self::assertCount(2, $calls);
    }

    public function testDestroyRemovesEverything(): void
    {
        $hub = EventHub::createEventHub();
        $count = 0;
        $hub->on('a', static function () use (&$count): void {
            $count++;
        });
        $hub->subscribe(static function () use (&$count): void {
            $count++;
        });

        $hub->destroy();
        $hub->emit('a');

        self::assertSame(0, $count);
    }
}
