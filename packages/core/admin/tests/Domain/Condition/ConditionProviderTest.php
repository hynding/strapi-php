<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Domain\Condition;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Domain\Condition\Condition as Domain;
use Strapi\Admin\Domain\Condition\Provider;

/** Port of server/src/domain/condition/__tests__/condition-provider.test.ts. */
final class ConditionProviderTest extends TestCase
{
    private bool $isLoaded = false;

    private function createConditionProvider(): Provider
    {
        return Provider::createConditionProvider(fn (): bool => $this->isLoaded);
    }

    /** @return array<string, mixed> */
    private static function attributes(string $name = 'foobar'): array
    {
        return ['name' => $name, 'displayName' => 'Foo bar', 'plugin' => 'foo', 'handler' => ['foo' => 'bar']];
    }

    public function testIsAProviderInstance(): void
    {
        $provider = $this->createConditionProvider();

        self::assertIsArray($provider->hooks);
        foreach (['register', 'registerMany', 'delete', 'get', 'values', 'keys', 'has', 'size', 'clear'] as $method) {
            self::assertTrue(method_exists($provider, $method), $method);
        }
    }

    public function testCanRegisterANewCondition(): void
    {
        $attributes = self::attributes();
        $id = Domain::computeConditionId($attributes);

        $provider = $this->createConditionProvider();
        $provider->register($attributes);

        $condition = $provider->get($id);
        self::assertNotNull($condition);
        self::assertArrayNotHasKey('name', $condition);
        self::assertSame($id, $condition['id']);
        self::assertSame('Foo bar', $condition['displayName']);
        self::assertSame('foo', $condition['plugin']);
        self::assertSame(['foo' => 'bar'], $condition['handler']);
    }

    public function testCantRegisterIfStrapiIsLoaded(): void
    {
        $this->isLoaded = true;

        $this->expectExceptionMessage("You can't register new conditions outside of the bootstrap function.");

        $this->createConditionProvider()->register(self::attributes());
    }

    public function testRegistrationHooksAreTriggered(): void
    {
        $calls = 0;
        $provider = $this->createConditionProvider();
        $provider->hooks['willRegister']->register(static function () use (&$calls): void {
            $calls++;
        });
        $provider->hooks['didRegister']->register(static function () use (&$calls): void {
            $calls++;
        });

        $provider->register(self::attributes());

        self::assertSame(2, $calls);
    }

    public function testCanRegisterMultipleConditions(): void
    {
        $provider = $this->createConditionProvider();
        $provider->registerMany([self::attributes('foobar-A'), self::attributes('foobar-B')]);

        self::assertSame(2, $provider->size());
    }

    public function testRegisterManyTriggersHooksMultipleTimes(): void
    {
        $calls = ['will' => 0, 'did' => 0];
        $provider = $this->createConditionProvider();
        $provider->hooks['willRegister']->register(static function () use (&$calls): void {
            $calls['will']++;
        });
        $provider->hooks['didRegister']->register(static function () use (&$calls): void {
            $calls['did']++;
        });

        $provider->registerMany([self::attributes('foobar-A'), self::attributes('foobar-B')]);

        self::assertSame(['will' => 2, 'did' => 2], $calls);
    }
}
