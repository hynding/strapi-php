<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Permission\PermissionsManager\PermissionFields;
use Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager;
use Strapi\Admin\Services\Permission\PermissionsManager\QueryBuilders;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;
use Strapi\Permissions\Engine\Abilities\Subject;

/** Port of server/src/services/__tests__/permissions-manager.test.ts. */
final class PermissionsManagerTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'article' => ['attributes' => ['title' => ['type' => 'text', 'private' => false]]],
            'foo' => ['attributes' => []],
            'bar' => ['attributes' => []],
        ]);
        self::$strapi = $strapi;
    }

    /** @param callable(AbilityBuilder): void $register */
    private static function defineAbility(callable $register): Ability
    {
        $builder = new AbilityBuilder();
        $register($builder);

        return $builder->build();
    }

    /** @param array{ability: Ability, action?: string|null, model?: string|null} $params */
    private static function createPermissionsManager(array $params): PermissionsManager
    {
        return PermissionsManager::createPermissionsManager(self::$strapi ?? throw new \LogicException(), $params);
    }

    public function testGetQueryEmptyWithoutConditions(): void
    {
        $pm = self::createPermissionsManager(['ability' => self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo')), 'action' => 'read', 'model' => 'foo']);

        self::assertSame([], $pm->getQuery());
    }

    public function testGetQueryFromAbility(): void
    {
        $pm = self::createPermissionsManager(['ability' => self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo', ['bar'], ['kai' => 'doe'])), 'action' => 'read', 'model' => 'foo']);

        self::assertSame(['$or' => [['kai' => 'doe']]], $pm->getQuery());
    }

    public function testGetQueryThrowsWithoutAction(): void
    {
        $pm = self::createPermissionsManager(['ability' => self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo', ['bar'], ['kai' => 'doe'])), 'model' => 'foo']);

        $this->expectException(\Throwable::class);
        $pm->getQuery();
    }

    public function testIsAllowedGrantsAccess(): void
    {
        $ability = self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo'));

        self::assertTrue(self::createPermissionsManager(['ability' => $ability, 'action' => 'read', 'model' => 'foo'])->isAllowed());
    }

    public function testIsAllowedDeniesAccess(): void
    {
        $ability = self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo'));

        self::assertFalse(self::createPermissionsManager(['ability' => $ability, 'action' => 'read', 'model' => 'bar'])->isAllowed());
    }

    public function testToSubjectWithDefaultModel(): void
    {
        $pm = self::createPermissionsManager(['ability' => self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo')), 'action' => 'read', 'model' => 'foo']);

        $sub = $pm->toSubject(['foo' => 'bar']);

        self::assertSame('foo', Subject::detectSubjectType($sub));
        self::assertIsArray($sub);
        self::assertSame('bar', $sub['foo']);
    }

    public function testToSubjectWithGivenModel(): void
    {
        $pm = self::createPermissionsManager(['ability' => self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'foo')), 'action' => 'read', 'model' => 'foo']);

        $sub = $pm->toSubject(['foo' => 'bar'], 'another_subject');

        self::assertSame('another_subject', Subject::detectSubjectType($sub));
    }

    private static function articleManager(): PermissionsManager
    {
        $ability = self::defineAbility(static function (AbilityBuilder $b): void {
            $b->can('read', 'article', ['title'], ['title' => 'foo']);
            $b->can('edit', 'article', ['title'], ['title' => ['$in' => ['kai', 'doe']]]);
        });

        return self::createPermissionsManager(['ability' => $ability, 'action' => 'read', 'model' => 'article']);
    }

    public function testPickAllFieldsUsingDefaultModel(): void
    {
        self::assertSame(['title' => 'foo'], self::articleManager()->pickPermittedFieldsOf(['title' => 'foo']));
    }

    public function testPickZeroFieldsUsingCustomAction(): void
    {
        self::assertSame([], self::articleManager()->pickPermittedFieldsOf(['title' => 'foo'], ['action' => 'edit']));
    }

    public function testSanitizeAnArrayOfObjects(): void
    {
        self::assertSame([['title' => 'foo'], []], self::articleManager()->pickPermittedFieldsOf([['title' => 'foo'], ['title' => 'kai']]));
    }

    private static function queryManager(): PermissionsManager
    {
        $ability = self::defineAbility(static fn (AbilityBuilder $b) => $b->can('read', 'article', ['title'], ['$and' => [['title' => 'foo']]]));

        return self::createPermissionsManager(['ability' => $ability, 'action' => 'read', 'model' => 'article']);
    }

    public function testAddPermissionsQueryToSimpleObject(): void
    {
        $pmQuery = ['$or' => [['$and' => [['title' => 'foo']]]]];

        self::assertSame(['limit' => 100, 'filters' => $pmQuery], self::queryManager()->addPermissionsQueryTo(['limit' => 100]));
    }

    public function testAddPermissionsQueryToComplexObject(): void
    {
        $pmQuery = ['$or' => [['$and' => [['title' => 'foo']]]]];
        $query = ['limit' => 100, 'filters' => ['$and' => [['a' => 'b'], ['c' => 'd']]]];

        self::assertSame(['limit' => 100, 'filters' => ['$and' => [$query['filters'], $pmQuery]]], self::queryManager()->addPermissionsQueryTo($query));
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function strapiQueryCases(): array
    {
        return [
            'No transform' => [['foo' => 'bar'], ['foo' => 'bar']],
            'Simple op' => [['foo' => ['$eq' => 'bar']], ['foo' => ['$eq' => 'bar']]],
            'Nested property' => [['foo.nested' => 'bar'], ['foo' => ['nested' => 'bar']]],
            'Nested property + $eq' => [['foo.nested' => ['$eq' => 'bar']], ['foo' => ['nested' => ['$eq' => 'bar']]]],
            'Nested property + $elementMatch' => [['foo.nested' => ['$elemMatch' => 'bar']], ['foo' => ['nested' => 'bar']]],
            'Deeply nested property' => [['foo.nested.again' => 'bar'], ['foo' => ['nested' => ['again' => 'bar']]]],
            'Op with array' => [['foo' => ['$in' => ['bar', 'rab']]], ['foo' => ['$in' => ['bar', 'rab']]]],
            'Removable op' => [['foo' => ['$elemMatch' => ['a' => 'b']]], ['foo' => ['a' => 'b']]],
            'Combination of removable and basic ops' => [['foo' => ['$elemMatch' => ['a' => ['$in' => [1, 2, 3]]]]], ['foo' => ['a' => ['$in' => [1, 2, 3]]]]],
            'Decoupling of nested properties with/without op' => [['foo' => ['$elemMatch' => ['a' => ['$in' => [1, 2, 3]], 'b' => 'c']]], ['foo' => ['a' => ['$in' => [1, 2, 3]], 'b' => 'c']]],
            'OR op and properties decoupling' => [['$or' => [['foo' => ['a' => 2]], ['foo' => ['b' => 3]]]], ['$or' => [['foo' => ['a' => 2]], ['foo' => ['b' => 3]]]]],
            'OR op with nested properties & ops' => [['$or' => [['foo' => ['a' => 2]], ['foo' => ['b' => ['$in' => [1, 2, 3]]]]]], ['$or' => [['foo' => ['a' => 2]], ['foo' => ['b' => ['$in' => [1, 2, 3]]]]]]],
            'Nested OR op' => [['$or' => [['$or' => [['a' => 2], ['a' => 3]]]]], ['$or' => [['$or' => [['a' => 2], ['a' => 3]]]]]],
            'OR op with nested AND op' => [['$or' => [['a' => 2], [['a' => 3], ['$or' => [['b' => 1], ['b' => 4]]]]]], ['$or' => [['a' => 2], [['a' => 3], ['$or' => [['b' => 1], ['b' => 4]]]]]]],
            'OR op with nested AND op and nested properties' => [['$or' => [['a' => 2], [['a' => 3], ['b' => ['c' => 'foo']]]]], ['$or' => [['a' => 2], [['a' => 3], ['b' => ['c' => 'foo']]]]]],
            'Literal nested property with removable op' => [
                ['created_by' => ['roles' => ['$elemMatch' => ['id' => ['$in' => [1, 2, 3]]]]]],
                ['created_by' => ['roles' => ['id' => ['$in' => [1, 2, 3]]]]],
            ],
        ];
    }

    #[DataProvider('strapiQueryCases')]
    public function testBuildStrapiQuery(mixed $input, mixed $expected): void
    {
        self::assertSame($expected, QueryBuilders::buildStrapiQuery($input));
    }

    public function testShouldIncludeAllWhenAnyRuleHasNoFieldRestriction(): void
    {
        $ability = self::defineAbility(static function (AbilityBuilder $b): void {
            $b->can('read', 'Article', null);
            $b->can('read', 'Article', ['title']);
        });

        $result = PermissionFields::createPermissionFieldsCache($ability)->getPermissionFields('read', Subject::subject('Article', []));

        self::assertTrue($result['shouldIncludeAll']);
        self::assertTrue($result['hasAtLeastOneRegistered']);
        self::assertSame([], $result['permittedFields']);
    }
}
