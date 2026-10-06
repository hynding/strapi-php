<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\HookContext;
use Strapi\Utils\ProviderFactory;

/** Port of __tests__/provider-factory.test.ts. */
final class ProviderFactoryTest extends TestCase
{
    public function testCanCreateADefaultProvider(): void
    {
        $provider = ProviderFactory::create();
        self::assertArrayHasKey('willRegister', $provider->hooks);
        self::assertArrayHasKey('didRegister', $provider->hooks);
        self::assertArrayHasKey('willDelete', $provider->hooks);
        self::assertArrayHasKey('didDelete', $provider->hooks);
        self::assertSame(0, $provider->size());
    }

    public function testWillRegisterHookCanMutateTheContext(): void
    {
        $provider = ProviderFactory::create();
        $called = false;
        $provider->hooks['willRegister']->register(static function (HookContext $context) use (&$called): void {
            $called = true;
            $context->value['foo'] = 'bar';
        });

        $provider->register('key', ['bar' => 'foo']);

        self::assertTrue($called);
        self::assertSame(['bar' => 'foo', 'foo' => 'bar'], $provider->get('key'));
    }

    /** @return iterable<array{string}> */
    public static function parallelHooks(): iterable
    {
        yield ['didRegister'];
        yield ['willDelete'];
        yield ['didDelete'];
    }

    #[DataProvider('parallelHooks')]
    public function testAsyncParallelHooksCannotMutateTheContext(string $hookName): void
    {
        $provider = ProviderFactory::create();
        $ctx = ['bar' => 'foo'];
        $provider->hooks[$hookName]->register(static function (array $context): array {
            $context['foo'] = 'bar';

            return $context;
        });

        $results = $provider->hooks[$hookName]->call($ctx);

        self::assertSame([['bar' => 'foo', 'foo' => 'bar']], $results);
        self::assertSame(['bar' => 'foo'], $ctx);
    }

    public function testCanRegisterANewItem(): void
    {
        $provider = ProviderFactory::create();
        $provider->register('key', ['foo' => 'bar']);
        self::assertSame(['foo' => 'bar'], $provider->get('key'));
    }

    public function testCannotRegisterDuplicatedKeyByDefault(): void
    {
        $provider = ProviderFactory::create();
        $provider->register('key', ['foo' => 'bar']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicated item key: key');
        $provider->register('key', ['bar' => 'foo']);
    }

    public function testCanRegisterDuplicatedKeyWhenThrowOnDuplicatesIsFalse(): void
    {
        $provider = ProviderFactory::create(['throwOnDuplicates' => false]);
        $provider->register('key', ['foo' => 'bar']);
        self::assertSame(['foo' => 'bar'], $provider->get('key'));
        $provider->register('key', ['bar' => 'foo']);
        self::assertSame(['bar' => 'foo'], $provider->get('key'));
    }

    public function testRegisterHooksAreTriggeredOnItemRegistration(): void
    {
        $provider = ProviderFactory::create();
        $willRegister = [];
        $didRegister = [];
        $provider->hooks['willRegister']->register(static function (HookContext $ctx) use (&$willRegister): void {
            $willRegister[] = [$ctx->key, $ctx->value];
            $ctx->value['bar'] = 'foo';
        });
        $provider->hooks['didRegister']->register(static function (array $ctx) use (&$didRegister): void {
            $didRegister[] = $ctx;
        });

        $provider->register('key', ['foo' => 'bar']);

        self::assertSame([['key', ['foo' => 'bar']]], $willRegister);
        self::assertSame([['key' => 'key', 'value' => ['foo' => 'bar', 'bar' => 'foo']]], $didRegister);
    }

    public function testDelete(): void
    {
        $provider = ProviderFactory::create();
        $willDelete = [];
        $didDelete = [];
        $provider->hooks['willDelete']->register(static function (array $ctx) use (&$willDelete): void {
            $willDelete[] = $ctx;
        });
        $provider->hooks['didDelete']->register(static function (array $ctx) use (&$didDelete): void {
            $didDelete[] = $ctx;
        });
        $provider->register('key', ['foo' => 'bar']);

        self::assertSame($provider, $provider->delete('key'));
        self::assertFalse($provider->has('key'));
        self::assertSame([['key' => 'key', 'value' => ['foo' => 'bar']]], $willDelete);
        self::assertSame([['key' => 'key', 'value' => ['foo' => 'bar']]], $didDelete);

        // deleting an unregistered item does nothing
        $provider->delete('nope');
        self::assertCount(1, $willDelete);
    }

    public function testGetValuesKeysHasSize(): void
    {
        $provider = ProviderFactory::create();
        self::assertNull($provider->get('nope'));
        self::assertSame([], $provider->values());
        self::assertSame([], $provider->keys());

        $provider->register('a', ['foo' => 'bar'])->register('b', ['bar' => 'foo']);

        self::assertSame([['foo' => 'bar'], ['bar' => 'foo']], $provider->values());
        self::assertSame(['a', 'b'], $provider->keys());
        self::assertTrue($provider->has('a'));
        self::assertFalse($provider->has('c'));
        self::assertSame(2, $provider->size());
    }

    public function testClear(): void
    {
        $provider = ProviderFactory::create();
        $deleted = [];
        $provider->hooks['didDelete']->register(static function (array $ctx) use (&$deleted): void {
            $deleted[] = $ctx['key'];
        });

        $provider->clear();
        self::assertSame([], $deleted);

        $provider->register('a', 1)->register('b', 2)->clear();
        self::assertSame(0, $provider->size());
        self::assertSame(['a', 'b'], $deleted);
    }
}
