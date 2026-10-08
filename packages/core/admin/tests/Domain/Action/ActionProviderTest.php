<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Domain\Action;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Domain\Action\Action as Domain;
use Strapi\Admin\Domain\Action\Provider;
use Strapi\Admin\Validation\CommonValidators;

/**
 * Port of server/src/domain/action/__tests__/action-provider.test.ts. `global.strapi` is stubbed with
 * the provider's `isLoaded` callable and {@see CommonValidators::$pluginNamesResolver}.
 */
final class ActionProviderTest extends TestCase
{
    private bool $isLoaded = false;

    protected function setUp(): void
    {
        $this->isLoaded = false;
        CommonValidators::$pluginNamesResolver = static fn (): array => ['bar'];
    }

    protected function tearDown(): void
    {
        CommonValidators::$pluginNamesResolver = null;
    }

    private function createActionProvider(): Provider
    {
        return Provider::createActionProvider([], fn (): bool => $this->isLoaded);
    }

    /** @return array<string, mixed> */
    private static function settingsAction(): array
    {
        return [
            'section' => 'settings',
            'displayName' => 'Foo bar',
            'uid' => 'foo',
            'pluginName' => 'bar',
            'category' => 'category',
            'subCategory' => 'subcategory',
        ];
    }

    /** @return array<string, mixed> */
    private static function contentTypeAction(bool $withFields = true): array
    {
        $action = [
            'section' => 'contentTypes',
            'subjects' => ['foo', 'bar'],
            'displayName' => 'Foo bar',
            'uid' => 'foobar',
        ];
        if ($withFields) {
            $action['options'] = ['applyToProperties' => ['fields']];
        }

        return $action;
    }

    public function testActionProviderIsAProviderInstance(): void
    {
        $actionProvider = $this->createActionProvider();

        self::assertIsArray($actionProvider->hooks);
        foreach (['register', 'registerMany', 'appliesToProperty', 'delete', 'get', 'values', 'keys', 'has', 'size', 'clear', 'unstable_aliases'] as $method) {
            self::assertTrue(method_exists($actionProvider, $method), $method);
        }
    }

    public function testCanRegisterANewAction(): void
    {
        $attributes = self::settingsAction();
        $actionId = Domain::computeActionId($attributes);

        $actionProvider = $this->createActionProvider();
        $actionProvider->register($attributes);

        $action = $actionProvider->get($actionId);
        self::assertNotNull($action);
        self::assertArrayNotHasKey('uid', $action);
        self::assertSame($actionId, $action['actionId']);
        unset($attributes['uid']);
        foreach ($attributes as $key => $value) {
            self::assertSame($value, $action[$key]);
        }
    }

    public function testCantRegisterAnActionIfStrapiIsLoaded(): void
    {
        $this->isLoaded = true;

        $this->expectExceptionMessage("You can't register new actions outside of the bootstrap function.");

        $this->createActionProvider()->register([...self::settingsAction(), 'invalidField' => 'invalid']);
    }

    public function testCantRegisterAnActionWithUnknownAttribute(): void
    {
        $this->expectException(\Throwable::class);

        $this->createActionProvider()->register([...self::settingsAction(), 'invalidField' => 'invalid']);
    }

    public function testRegistrationHooksAreTriggered(): void
    {
        $calls = ['willRegister' => 0, 'didRegister' => 0];
        $actionProvider = $this->createActionProvider();
        $actionProvider->hooks['willRegister']->register(static function () use (&$calls): void {
            $calls['willRegister']++;
        });
        $actionProvider->hooks['didRegister']->register(static function () use (&$calls): void {
            $calls['didRegister']++;
        });

        $actionProvider->register(self::settingsAction());

        self::assertSame(1, $calls['willRegister']);
        self::assertSame(1, $calls['didRegister']);
    }

    public function testCanRegisterMultipleActionsAtOnce(): void
    {
        $attributes = [
            self::settingsAction(),
            ['section' => 'contentTypes', 'subjects' => ['foo', 'bar'], 'displayName' => 'Bar foo', 'uid' => 'bar'],
        ];

        $actionProvider = $this->createActionProvider();
        $actionProvider->registerMany($attributes);

        self::assertSame(2, $actionProvider->size());
    }

    public function testIfOneActionIsInvalidNoneIsRegistered(): void
    {
        $attributes = [
            self::settingsAction(),
            ['section' => 'contentTypes', 'displayName' => 'Bar foo', 'uid' => 'bar'],
        ];

        $actionProvider = $this->createActionProvider();

        try {
            $actionProvider->registerMany($attributes);
            self::fail('Expected an error');
        } catch (\Throwable) {
        }

        self::assertSame(0, $actionProvider->size());
    }

    public function testRegisterManyTriggersHooksMultipleTimes(): void
    {
        $calls = ['willRegister' => 0, 'didRegister' => 0];
        $actionProvider = $this->createActionProvider();
        $actionProvider->hooks['willRegister']->register(static function () use (&$calls): void {
            $calls['willRegister']++;
        });
        $actionProvider->hooks['didRegister']->register(static function () use (&$calls): void {
            $calls['didRegister']++;
        });

        $actionProvider->registerMany([
            self::settingsAction(),
            ['section' => 'contentTypes', 'subjects' => ['foo', 'bar'], 'displayName' => 'Bar foo', 'uid' => 'bar'],
        ]);

        self::assertSame(2, $calls['willRegister']);
        self::assertSame(2, $calls['didRegister']);
    }

    public function testAppliesToPropertyFalseWhenPropertyCannotBeApplied(): void
    {
        $action = self::contentTypeAction(false);
        $actionProvider = $this->createActionProvider();
        $actionProvider->register($action);

        self::assertFalse($actionProvider->appliesToProperty('fields', Domain::computeActionId($action), null));
    }

    public function testAppliesToPropertyTrueWithoutSubject(): void
    {
        $action = self::contentTypeAction();
        $actionProvider = $this->createActionProvider();
        $actionProvider->register($action);

        self::assertTrue($actionProvider->appliesToProperty('fields', Domain::computeActionId($action), null));
    }

    public function testAppliesToPropertyFalseWhenSubjectNotHandled(): void
    {
        $action = self::contentTypeAction();
        $actionProvider = $this->createActionProvider();
        $actionProvider->register($action);

        self::assertFalse($actionProvider->appliesToProperty('fields', Domain::computeActionId($action), 'foobar'));
    }

    public function testAppliesToPropertyTrueWithoutHooks(): void
    {
        $action = self::contentTypeAction();
        $actionProvider = $this->createActionProvider();
        $actionProvider->register($action);

        self::assertTrue($actionProvider->appliesToProperty('fields', Domain::computeActionId($action), 'foo'));
    }

    /**
     * @param list<mixed> $results
     */
    private function runHooks(array $results): array
    {
        $action = self::contentTypeAction();
        $actionId = Domain::computeActionId($action);
        $actionProvider = $this->createActionProvider();

        $received = [];
        foreach ($results as $result) {
            $actionProvider->hooks['appliesPropertyToSubject']->register(static function (array $ctx) use (&$received, $result): mixed {
                $received[] = $ctx;

                return $result;
            });
        }

        $actionProvider->register($action);
        $applies = $actionProvider->appliesToProperty('fields', $actionId, 'foo');

        return [$applies, $received, $actionProvider->get($actionId)];
    }

    public function testAppliesToPropertyFalseIfOneHookReturnsFalse(): void
    {
        [$applies, $received, $actionFromProvider] = $this->runHooks([true, null, 2, [], false]);

        self::assertFalse($applies);
        self::assertCount(5, $received);
        foreach ($received as $ctx) {
            self::assertSame(['property' => 'fields', 'action' => $actionFromProvider, 'subject' => 'foo'], $ctx);
        }
    }

    public function testAppliesToPropertyTrueIfNoHookReturnsFalse(): void
    {
        [$applies, $received, $actionFromProvider] = $this->runHooks([true, null, 2, [], new \DateTimeImmutable()]);

        self::assertTrue($applies);
        self::assertCount(5, $received);
        foreach ($received as $ctx) {
            self::assertSame(['property' => 'fields', 'action' => $actionFromProvider, 'subject' => 'foo'], $ctx);
        }
    }

    /** @param list<array{actionId: string, subjects?: list<string>}>|null $aliases */
    private function aliasProvider(?array $aliases): Provider
    {
        $bar = ['section' => 'settings', 'displayName' => 'Bar foo', 'category' => 'category', 'uid' => 'bar'];
        if ($aliases !== null) {
            $bar['aliases'] = $aliases;
        }
        $provider = $this->createActionProvider();
        $provider->registerMany([self::settingsAction(), $bar]);

        return $provider;
    }

    public function testAliasesEmptyWhenNoAlias(): void
    {
        self::assertSame([], $this->aliasProvider(null)->unstable_aliases('plugin::bar.foo'));
    }

    public function testAliasesEmptyWhenSubjectDoesntMatch(): void
    {
        self::assertSame([], $this->aliasProvider([['actionId' => 'plugin::bar.foo', 'subjects' => ['baz']]])->unstable_aliases('plugin::bar.foo', 'bar'));
    }

    public function testAliasesEmptyWhenSubjectRequiredButMissing(): void
    {
        self::assertSame([], $this->aliasProvider([['actionId' => 'plugin::bar.foo', 'subjects' => ['baz']]])->unstable_aliases('plugin::bar.foo'));
    }

    public function testAliasesOneResult(): void
    {
        self::assertSame(['api::bar'], $this->aliasProvider([['actionId' => 'plugin::bar.foo']])->unstable_aliases('plugin::bar.foo'));
    }

    public function testAliasesOneResultWithValidSubject(): void
    {
        self::assertSame(['api::bar'], $this->aliasProvider([['actionId' => 'plugin::bar.foo', 'subjects' => ['baz']]])->unstable_aliases('plugin::bar.foo', 'baz'));
    }
}
