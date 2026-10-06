<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\HookContext;
use Strapi\Utils\Hooks;

/** Port of __tests__/hooks.test.ts. */
final class HooksTest extends TestCase
{
    public function testCreateHookHasAllMethods(): void
    {
        $hook = Hooks::createHook();
        self::assertSame([], $hook->getHandlers());
        self::assertSame($hook, $hook->register(static fn () => null));
        self::assertCount(1, $hook->getHandlers());
    }

    public function testDeletingAHandlerRetainsTheOthers(): void
    {
        $removed = static fn (): string => 'removed';
        $retained = static fn (): string => 'retained';
        $hook = Hooks::createHook();
        $hook->register($removed)->register($retained)->register($removed);
        $previousHandlers = $hook->getHandlers();

        self::assertSame($hook, $hook->delete($removed));
        self::assertSame([$retained], $hook->getHandlers());
        self::assertSame([$removed, $retained, $removed], $previousHandlers);
    }

    public function testCallIsNotImplementedByDefault(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Method not implemented');
        Hooks::createHook()->call();
    }

    public function testAsyncSeriesHook(): void
    {
        $hook = Hooks::createAsyncSeriesHook();
        $ctx = new HookContext('k', ['foo' => []]);
        $hook->call($ctx);
        self::assertSame(['foo' => []], $ctx->value);

        $hook->register(static function (HookContext $context): void {
            $context->value['foo'][] = 'foo';
        });
        $hook->register(static function (HookContext $context): void {
            $context->value['foo'][] = 'bar';
        });
        $hook->call($ctx);

        self::assertSame(['foo' => ['foo', 'bar']], $ctx->value);
    }

    public function testAsyncSeriesWaterfallHook(): void
    {
        $hook = Hooks::createAsyncSeriesWaterfallHook();
        self::assertSame('foo', $hook->call('foo'));

        $hook->register(static fn (string $param): string => "{$param}.bar");
        $hook->register(static fn (string $param): string => "{$param}.foobar");

        self::assertSame('foo.bar.foobar', $hook->call('foo'));
    }

    public function testAsyncParallelHook(): void
    {
        $hook = Hooks::createAsyncParallelHook();
        self::assertSame([], $hook->call('foo'));

        $hook->register(static fn (string $param): string => "{$param}.foo");
        $hook->register(static fn (string $param): string => "{$param}.bar");

        self::assertSame(['test.foo', 'test.bar'], $hook->call('test'));
    }

    public function testAsyncParallelHookHandsEachHandlerACopy(): void
    {
        $hook = Hooks::createAsyncParallelHook();
        $ctx = new \stdClass();
        $ctx->bar = 'foo';
        $hook->register(static function (\stdClass $context): \stdClass {
            $context->foo = 'bar';

            return $context;
        });

        $results = $hook->call($ctx);

        self::assertSame('bar', $results[0]->foo);
        self::assertFalse(isset($ctx->foo));
    }

    public function testAsyncBailHook(): void
    {
        $hook = Hooks::createAsyncBailHook();
        self::assertNull($hook->call('x'));

        $hook->register(static fn (): mixed => null);
        $hook->register(static fn (): string => 'first');
        $hook->register(static fn (): string => 'second');

        self::assertSame('first', $hook->call('x'));
    }
}
