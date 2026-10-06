<?php

declare(strict_types=1);

namespace Strapi\Permissions\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Subject;
use Strapi\Permissions\Engine\Engine;
use Strapi\Permissions\Engine\Hooks\BeforeEvaluateContext;
use Strapi\Permissions\Engine\Hooks\WillRegisterContext;
use Strapi\Utils\ProviderFactory;

/** Port of __tests__/permissions.engine.vitest.test.ts. */
final class PermissionsEngineTest extends TestCase
{
    private const ALLOWED_CONDITION = 'plugin::test.isAuthor';
    private const DENIED_CONDITION = 'plugin::test.isAdmin';

    /** @return list<array{name: string, category: string, handler: \Closure}> */
    private static function conditions(): array
    {
        return [
            ['name' => 'plugin::test.isAuthor', 'category' => 'default', 'handler' => static fn (): bool => true],
            ['name' => 'plugin::test.isAdmin', 'category' => 'default', 'handler' => static fn (): bool => false],
            ['name' => 'hasId125', 'category' => 'default', 'handler' => static fn (): array => ['id' => 125]],
            ['name' => 'hasId200', 'category' => 'default', 'handler' => static fn (): array => ['id' => 200]],
            ['name' => 'unsupportedOperator', 'category' => 'default', 'handler' => static fn (): array => ['title' => ['$startsWith' => 'Test']]],
        ];
    }

    /**
     * The default providers: a condition provider whose `get()` falls back to an always-true
     * condition for unknown names (as upstream's `Object.assign(providers.condition, { get })`).
     *
     * @return array{action: ProviderFactory<mixed>, condition: object}
     */
    private static function providers(): array
    {
        $conditions = self::conditions();

        return [
            'action' => ProviderFactory::create(),
            'condition' => new class($conditions) {
                /** @param list<array{name: string, category: string, handler: \Closure}> $conditions */
                public function __construct(private readonly array $conditions)
                {
                }

                /** @return array{name?: string, category?: string, handler: \Closure} */
                public function get(string $condition): array
                {
                    foreach ($this->conditions as $c) {
                        if ($c['name'] === $condition) {
                            return $c;
                        }
                    }

                    return ['handler' => static fn (): bool => true];
                }
            },
        ];
    }

    /** Create an engine hook function that rejects a specific action. */
    private static function generateInvalidateActionHook(string $action): \Closure
    {
        return static function (object $params) use ($action): ?bool {
            if ($params->permission['action'] === $action) {
                return false;
            }

            return null;
        };
    }

    /**
     * Build an engine, add all given hooks, and generate an ability. Register functions are
     * recorded as upstream does with `vi.spyOn(engine, 'createRegisterFunction')`.
     *
     * @param list<array<string, mixed>> $permissions
     * @param list<array{name: string, fn: callable}> $engineHooks
     * @param array{action: object, condition: object}|null $engineProviders
     * @param array<string, mixed> $abilityOptions
     * @return array{engine: Engine, ability: Ability, createRegisterFunctionCalls: int, registerFunctions: list<array{calls: list<array<string, mixed>>}>}
     */
    private static function buildEngineWithAbility(array $permissions, array $engineHooks = [], ?array $engineProviders = null, array $abilityOptions = []): array
    {
        $engine = Engine::new(['providers' => $engineProviders ?? self::providers()]);
        foreach ($engineHooks as ['name' => $name, 'fn' => $fn]) {
            $engine->on($name, $fn);
        }

        $createRegisterFunctionCalls = 0;
        $registerFunctions = [];
        $engine->setCreateRegisterFunction(static function (callable $can, array $options) use ($engine, &$createRegisterFunctionCalls, &$registerFunctions): \Closure {
            $createRegisterFunctionCalls++;
            $index = count($registerFunctions);
            $registerFunctions[$index] = ['calls' => []];
            $original = $engine->defaultRegisterFunction($can, $options);

            return static function (array $permission) use (&$registerFunctions, $index, $original): mixed {
                $registerFunctions[$index]['calls'][] = $permission;

                return $original($permission);
            };
        });

        $ability = $engine->generateAbility($permissions, $abilityOptions);

        return ['engine' => $engine, 'ability' => $ability, 'createRegisterFunctionCalls' => $createRegisterFunctionCalls, 'registerFunctions' => $registerFunctions];
    }

    /**
     * Build an ability from a single condition whose handler returns `$query`.
     */
    private static function abilityForQuery(mixed $query): Ability
    {
        $condition = ProviderFactory::create();
        $condition->register('test.dynamicCondition', ['name' => 'test.dynamicCondition', 'handler' => static fn (): mixed => $query]);

        $permissions = [['action' => 'read', 'subject' => 'article', 'conditions' => ['test.dynamicCondition']]];

        return self::buildEngineWithAbility($permissions, [], ['action' => ProviderFactory::create(), 'condition' => $condition])['ability'];
    }

    /**
     * Map permissions to the expected ability rules (`toMatchObject` subset).
     *
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    private static function expectedAbilityRules(array $permissions): array
    {
        return array_map(static function (array $permission): array {
            $rule = $permission;
            unset($rule['properties'], $rule['conditions']);
            if (isset($permission['properties']['fields'])) {
                $rule['fields'] = $permission['properties']['fields'];
            }
            if (!isset($permission['subject'])) {
                $rule['subject'] = 'all';
            }

            return $rule;
        }, $permissions);
    }

    /**
     * `expect(ability.rules).toMatchObject(expected)`: same length, each expected key present with the same value.
     *
     * @param list<array<string, mixed>> $expected
     */
    private static function assertRulesMatch(array $expected, Ability $ability): void
    {
        $rules = $ability->rulesAsArrays();
        self::assertCount(count($expected), $rules);
        foreach ($expected as $i => $subset) {
            foreach ($subset as $key => $value) {
                self::assertArrayHasKey($key, $rules[$i]);
                self::assertEquals($value, $rules[$i][$key]);
            }
        }
    }

    /** @param array<string, mixed> $permission */
    private static function withoutConditions(array $permission): array
    {
        unset($permission['conditions']);

        return $permission;
    }

    public function testRegistersActionWithoutSubject(): void
    {
        $permissions = [['action' => 'read'], ['action' => 'write']];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions, 'createRegisterFunctionCalls' => $calls] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read', 'all'));
        self::assertFalse($ability->can('i_dont_exist', 'all'));

        self::assertSame(2, $calls);
        self::assertEquals([['action' => 'read', 'subject' => null, 'properties' => null]], $registerFunctions[0]['calls']);
        self::assertEquals([['action' => 'write', 'subject' => null, 'properties' => null]], $registerFunctions[1]['calls']);
    }

    public function testRegistersActionWithoutSubjectWithSubjectAll(): void
    {
        $permissions = [['action' => 'read', 'subject' => null], ['action' => 'read']];
        ['ability' => $ability, 'createRegisterFunctionCalls' => $calls] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read', 'all'));
        self::assertSame(2, $calls);
        self::assertRulesMatch(self::expectedAbilityRules($permissions), $ability);
    }

    public function testRegistersActionWithSubjectString(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article']];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 123])));
        self::assertEquals(['action' => 'read', 'subject' => 'article', 'properties' => null], $registerFunctions[0]['calls'][0]);
    }

    public function testRegistersActionWithSubjectObject(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['**']], 'conditions' => ['hasId125']]];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', 'article', 'name'));
        self::assertFalse($ability->can('read', Subject::subject('article', ['id' => 123])));
        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 125])));
        self::assertFalse($ability->can('read', Subject::subject('user', ['id' => 125])));

        self::assertEquals(
            [...self::withoutConditions($permissions[0]), 'condition' => ['$and' => [['$or' => [['id' => 125]]]]]],
            $registerFunctions[0]['calls'][0],
        );
    }

    public function testRegistersActionWithSubjectAndProperties(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['title']]]];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions);

        self::assertFalse($ability->can('read', 'all'));
        self::assertFalse($ability->can('read', 'user'));
        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', 'article', 'title'));
        self::assertFalse($ability->can('read', 'article', 'title.nested'));
        self::assertFalse($ability->can('read', 'article', 'name'));

        self::assertEquals($permissions[0], $registerFunctions[0]['calls'][0]);
    }

    public function testThrowsOnEmptyFieldsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`rawRule.fields` cannot be an empty array. https://bit.ly/390miLa');
        self::buildEngineWithAbility([['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => []]]]);
    }

    public function testHandlesNestedPropertiesCorrectly(): void
    {
        $permissions = [
            ['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['**']]],
            ['action' => 'post', 'subject' => 'article', 'properties' => ['fields' => ['*']]],
        ];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions);

        self::assertRulesMatch(self::expectedAbilityRules($permissions), $ability);

        self::assertFalse($ability->can('read', 'all'));
        self::assertFalse($ability->can('read', 'user'));
        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', 'article', 'title'));
        self::assertTrue($ability->can('read', 'article', 'title.nested'));
        self::assertTrue($ability->can('read', 'article', 'name'));
        self::assertTrue($ability->can('read', 'article', 'name.nested'));

        self::assertTrue($ability->can('post', 'article', 'title'));
        self::assertFalse($ability->can('post', 'article', 'title.nested'));
        self::assertTrue($ability->can('post', 'article', 'name'));
        self::assertFalse($ability->can('post', 'article', 'name.nested'));

        self::assertEquals($permissions[0], $registerFunctions[0]['calls'][0]);
        self::assertEquals($permissions[1], $registerFunctions[1]['calls'][0]);
    }

    public function testDoesNotRegisterActionWhenConditionsNotMet(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['title']], 'conditions' => [self::DENIED_CONDITION]]];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions, 'createRegisterFunctionCalls' => $calls] = self::buildEngineWithAbility($permissions);

        self::assertRulesMatch([], $ability);
        self::assertFalse($ability->can('read', 'all'));
        self::assertFalse($ability->can('read', 'user'));
        self::assertFalse($ability->can('read', 'article', 'name'));
        self::assertFalse($ability->can('read', 'article'));
        self::assertFalse($ability->can('read', 'article', 'title'));

        self::assertSame(1, $calls);
        self::assertSame([], $registerFunctions[0]['calls']);
    }

    public function testRegistersAnActionWhenConditionsAreMet(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['title']], 'conditions' => [self::ALLOWED_CONDITION]]];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions, 'createRegisterFunctionCalls' => $calls] = self::buildEngineWithAbility($permissions);

        self::assertRulesMatch(self::expectedAbilityRules($permissions), $ability);
        self::assertFalse($ability->can('read', 'all'));
        self::assertFalse($ability->can('read', 'user'));
        self::assertFalse($ability->can('read', 'article', 'name'));
        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', 'article', 'title'));

        self::assertSame(1, $calls);
        self::assertEquals(self::withoutConditions($permissions[0]), $registerFunctions[0]['calls'][0]);
    }

    public function testShowsCorrectErrorMessagesOnUnsupportedOperators(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'conditions' => ['unsupportedOperator']]];
        ['ability' => $ability] = self::buildEngineWithAbility($permissions);

        // sift only matches a concrete entity, so the throw fires here (write path)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsupported operator "\$startsWith"/i');
        $ability->can('read', Subject::subject('article', ['title' => 'Testing']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function nestedUnsupported(): iterable
    {
        yield '$or' => [['$or' => [['title' => ['$startsWith' => 'x']]]]];
        yield '$and' => [['$and' => [['title' => ['$startsWith' => 'x']]]]];
        yield '$elemMatch' => [['tags' => ['$elemMatch' => ['title' => ['$startsWith' => 'x']]]]];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('nestedUnsupported')]
    public function testRejectsAnUnsupportedOperatorNestedInsideStructuralOperators(array $query): void
    {
        $ability = self::abilityForQuery($query);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsupported operator "\$startsWith"/i');
        $ability->can('read', Subject::subject('article', ['title' => 'x', 'tags' => [['title' => 'x']]]));
    }

    /** @return iterable<array{string}> */
    public static function valueOperators(): iterable
    {
        yield ['$eq'];
        yield ['$ne'];
    }

    #[DataProvider('valueOperators')]
    public function testPreservesALiteralObjectOperandUnderValueOperators(string $op): void
    {
        $ability = self::abilityForQuery(['metadata' => [$op => ['$custom' => true]]]);

        $result = $ability->can('read', Subject::subject('article', ['metadata' => ['$custom' => true]]));
        self::assertSame($op === '$eq', $result);
    }

    public function testPreservesObjectLiteralEqualityCarryingDollarPrefixedDataKeys(): void
    {
        $ability = self::abilityForQuery(['meta' => ['flags' => ['$custom' => 1]]]);

        self::assertTrue($ability->can('read', Subject::subject('article', ['meta' => ['flags' => ['$custom' => 1]]])));
        self::assertFalse($ability->can('read', Subject::subject('article', ['meta' => ['flags' => ['$custom' => 2]]])));
    }

    public function testNamesTheOffendingOperatorAndListsTheSupportedSet(): void
    {
        $ability = self::abilityForQuery(['title' => ['$startsWith' => 'X']]);

        try {
            $ability->can('read', Subject::subject('article', ['title' => 'X']));
            self::fail('expected throw');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/unsupported operator "\$startsWith"/i', $e->getMessage());
            self::assertMatchesRegularExpression('/\$or, \$and, \$eq[\s\S]*\$elemMatch/', $e->getMessage());
        }
    }

    public function testAcceptsEveryOperatorOnTheSupportedWhitelist(): void
    {
        $ability = self::abilityForQuery(['$and' => [
            ['$or' => [['id' => ['$eq' => 1]], ['id' => ['$ne' => 2]]]],
            ['id' => ['$in' => [1]]],
            ['id' => ['$nin' => [9]]],
            ['views' => ['$lt' => 10]],
            ['views' => ['$lte' => 10]],
            ['views' => ['$gt' => 0]],
            ['views' => ['$gte' => 0]],
            ['title' => ['$exists' => true]],
            ['tags' => ['$elemMatch' => ['name' => ['$eq' => 'x']]]],
        ]]);

        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 1, 'views' => 5, 'title' => 'X', 'tags' => [['name' => 'x']]])));
        self::assertFalse($ability->can('read', Subject::subject('article', ['id' => 9, 'views' => 5, 'title' => 'X', 'tags' => [['name' => 'x']]])));
    }

    public function testSkipsAnUnregisteredConditionAndDeniesAccess(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['title']], 'conditions' => ['plugin::test.doesNotExist']]];
        ['ability' => $ability] = self::buildEngineWithAbility($permissions, [], ['action' => ProviderFactory::create(), 'condition' => ProviderFactory::create()]);

        self::assertFalse($ability->can('read', 'article'));
        self::assertFalse($ability->can('read', 'article', 'title'));
    }

    public function testSkipsAnUnregisteredConditionButStillGrantsViaAValidOne(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['title']], 'conditions' => [self::ALLOWED_CONDITION, 'plugin::test.doesNotExist']]];
        $conditionProvider = ProviderFactory::create();
        $conditionProvider->register(self::ALLOWED_CONDITION, self::conditions()[0]);

        ['ability' => $ability] = self::buildEngineWithAbility($permissions, [], ['action' => ProviderFactory::create(), 'condition' => $conditionProvider]);

        self::assertTrue($ability->can('read', 'article', 'title'));
        self::assertFalse($ability->can('read', 'article', 'name'));
    }

    public function testSkipsAConditionWhoseHandlerIsNotAFunctionAndDeniesAccess(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['title']], 'conditions' => [self::ALLOWED_CONDITION, 'plugin::test.malformedHandler']]];
        $conditionProvider = ProviderFactory::create();
        $conditionProvider->register('plugin::test.malformedHandler', ['name' => 'plugin::test.malformedHandler', 'handler' => 'not a function']);

        ['ability' => $ability] = self::buildEngineWithAbility($permissions, [], ['action' => ProviderFactory::create(), 'condition' => $conditionProvider]);

        self::assertFalse($ability->can('read', 'article', 'title'));
    }

    public function testFormatPermissionHookModifiesPermissions(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article']];
        $newPermissions = [['action' => 'view', 'subject' => 'article']];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions, [
            ['name' => 'format.permission', 'fn' => static fn (): array => $newPermissions[0]],
        ]);

        self::assertRulesMatch(self::expectedAbilityRules($newPermissions), $ability);
        self::assertFalse($ability->can('read', 'all'));
        self::assertTrue($ability->can('view', 'article'));
        self::assertEquals(['action' => 'view', 'subject' => 'article', 'properties' => null], $registerFunctions[0]['calls'][0]);
    }

    public function testBeforeFormatValidateCanPreventActionRegister(): void
    {
        ['ability' => $ability, 'registerFunctions' => $registerFunctions, 'createRegisterFunctionCalls' => $calls] = self::buildEngineWithAbility([['action' => 'read', 'subject' => 'article']], [
            ['name' => 'before-format::validate.permission', 'fn' => self::generateInvalidateActionHook('read')],
        ]);

        self::assertRulesMatch([], $ability);
        self::assertFalse($ability->can('read', 'article'));
        self::assertFalse($ability->can('read', 'user'));
        self::assertSame(1, $calls);
        self::assertSame([], $registerFunctions[0]['calls']);
    }

    public function testAfterFormatValidateCanPreventActionRegister(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article'], ['action' => 'read', 'subject' => 'user'], ['action' => 'write', 'subject' => 'article']];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions, 'createRegisterFunctionCalls' => $calls] = self::buildEngineWithAbility($permissions, [
            ['name' => 'after-format::validate.permission', 'fn' => self::generateInvalidateActionHook('read')],
        ]);

        self::assertRulesMatch(self::expectedAbilityRules([['action' => 'write', 'subject' => 'article']]), $ability);
        self::assertFalse($ability->can('read', 'article'));
        self::assertFalse($ability->can('read', 'user'));
        self::assertTrue($ability->can('write', 'article'));
        self::assertSame(3, $calls);
        self::assertSame([], $registerFunctions[0]['calls']);
        self::assertSame([], $registerFunctions[1]['calls']);
        self::assertCount(1, $registerFunctions[2]['calls']);
    }

    public function testValidateHooksExecuteInTheCorrectOrder(): void
    {
        $permissions = [['action' => 'update'], ['action' => 'delete'], ['action' => 'view']];
        $newPermissions = [['action' => 'modify'], ['action' => 'remove']];

        ['ability' => $ability] = self::buildEngineWithAbility($permissions, [
            ['name' => 'format.permission', 'fn' => static function (array $permission): array {
                return match ($permission['action']) {
                    'update' => [...$permission, 'action' => 'modify'],
                    'delete' => [...$permission, 'action' => 'remove'],
                    'view' => [...$permission, 'action' => 'read'],
                    default => $permission,
                };
            }],
            ['name' => 'before-format::validate.permission', 'fn' => self::generateInvalidateActionHook('modify')],
            ['name' => 'before-format::validate.permission', 'fn' => self::generateInvalidateActionHook('view')],
            ['name' => 'after-format::validate.permission', 'fn' => self::generateInvalidateActionHook('update')],
        ]);

        self::assertRulesMatch(self::expectedAbilityRules($newPermissions), $ability);
        self::assertFalse($ability->can('update', 'all'));
        self::assertTrue($ability->can('modify', 'all'));
        self::assertFalse($ability->can('delete', 'all'));
        self::assertTrue($ability->can('remove', 'all'));
        self::assertFalse($ability->can('view', 'all'));
    }

    public function testMultiRoleConditionMergingBothConditionsGrantAccessIndependently(): void
    {
        $permissions = [
            ['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['**']], 'conditions' => ['hasId125']],
            ['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['**']], 'conditions' => ['hasId200']],
        ];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 125])));
        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 200])));
        self::assertFalse($ability->can('read', Subject::subject('article', ['id' => 999])));

        self::assertEquals([...self::withoutConditions($permissions[0]), 'condition' => ['$and' => [['$or' => [['id' => 125]]]]]], $registerFunctions[0]['calls'][0]);
        self::assertEquals([...self::withoutConditions($permissions[1]), 'condition' => ['$and' => [['$or' => [['id' => 200]]]]]], $registerFunctions[1]['calls'][0]);
    }

    public function testMultiRoleConditionMergingUnconditionalPermissionGrantsUnrestrictedAccess(): void
    {
        $permissions = [
            ['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['**']], 'conditions' => ['hasId125']],
            ['action' => 'read', 'subject' => 'article', 'properties' => ['fields' => ['**']]],
        ];
        ['ability' => $ability, 'registerFunctions' => $registerFunctions] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 999])));

        self::assertEquals([...self::withoutConditions($permissions[0]), 'condition' => ['$and' => [['$or' => [['id' => 125]]]]]], $registerFunctions[0]['calls'][0]);
        self::assertEquals(self::withoutConditions($permissions[1]), $registerFunctions[1]['calls'][0]);
    }

    public function testBeforeHooksExecuteInTheCorrectOrder(): void
    {
        $called = '';
        $beforeEvaluateCalls = [];
        $beforeRegisterCalls = [];
        $permissions = [['action' => 'read', 'subject' => 'article', 'conditions' => [self::ALLOWED_CONDITION]]];

        self::buildEngineWithAbility($permissions, [
            ['name' => 'before-evaluate.permission', 'fn' => static function (BeforeEvaluateContext $ctx) use (&$called, &$beforeEvaluateCalls): void {
                $called = 'beforeEvaluate';
                $beforeEvaluateCalls[] = $ctx->permission();
            }],
            ['name' => 'before-register.permission', 'fn' => static function (WillRegisterContext $ctx) use (&$called, &$beforeRegisterCalls): void {
                self::assertSame('beforeEvaluate', $called);
                $called = 'beforeRegister';
                $beforeRegisterCalls[] = $ctx->permission();
                self::assertNotNull($ctx->condition);
            }],
        ]);

        self::assertSame([$permissions[0]], $beforeEvaluateCalls);
        self::assertEquals([['action' => 'read', 'subject' => 'article', 'properties' => null]], $beforeRegisterCalls);
        self::assertSame('beforeRegister', $called);
    }

    public function testBeforeEvaluateHookCanAddConditions(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article']];
        ['ability' => $ability] = self::buildEngineWithAbility($permissions, [
            ['name' => 'before-evaluate.permission', 'fn' => static fn (BeforeEvaluateContext $ctx) => $ctx->addCondition('hasId125')],
        ]);

        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 125])));
        self::assertFalse($ability->can('read', Subject::subject('article', ['id' => 1])));
    }

    public function testBeforeRegisterHookCanExtendConditions(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'conditions' => ['hasId125']]];
        ['ability' => $ability] = self::buildEngineWithAbility($permissions, [
            ['name' => 'before-register.permission', 'fn' => static function (WillRegisterContext $ctx): void {
                $ctx->condition->or(['id' => 7])->and(['published' => true]);
            }],
        ], null, ['user' => ['id' => 1]]);

        $rule = $ability->rules()[0];
        self::assertSame(['$and' => [['$or' => [['id' => 125], ['id' => 7]]], ['published' => true]]], $rule->conditions);
        self::assertTrue($ability->can('read', Subject::subject('article', ['id' => 7, 'published' => true])));
        self::assertFalse($ability->can('read', Subject::subject('article', ['id' => 7, 'published' => false])));
    }

    public function testParametrizedActionsAndInvalidHooks(): void
    {
        $permissions = [['action' => 'read', 'subject' => 'article', 'actionParameters' => ['locale' => 'en']]];
        ['ability' => $ability] = self::buildEngineWithAbility($permissions);

        self::assertTrue($ability->can('read?locale=en', 'article'));
        self::assertTrue($ability->can(['name' => 'read', 'params' => ['locale' => 'en']], 'article'));
        self::assertFalse($ability->can('read', 'article'));

        $engine = Engine::new(['providers' => self::providers()]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid hook supplied when trying to register an handler to the permission engine. Got "nope"');
        $engine->on('nope', static fn () => null);
    }
}
