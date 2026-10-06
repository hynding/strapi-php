<?php

declare(strict_types=1);

namespace Strapi\Permissions\Tests\Engine;

use PHPUnit\Framework\TestCase;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;
use Strapi\Permissions\Engine\Abilities\CaslAbility;
use Strapi\Permissions\Engine\Abilities\Rule;
use Strapi\Permissions\Engine\Abilities\Subject;

final class AbilityTest extends TestCase
{
    private static function ability(): Ability
    {
        $builder = new AbilityBuilder();
        $builder->can('read', 'article', ['title', 'body'], ['published' => true]);
        $builder->can('read', 'article', ['secret'], ['author' => 1]);
        $builder->can('update', 'article');
        $builder->cannot('update', 'article', ['locked']);
        $builder->can('manage', 'comment');

        return $builder->build();
    }

    public function testCanWithConditionsFieldsAndInvertedRules(): void
    {
        $ability = self::ability();

        self::assertTrue($ability->can('read', 'article'));
        self::assertTrue($ability->can('read', Subject::subject('article', ['published' => true])));
        self::assertFalse($ability->can('read', Subject::subject('article', ['published' => false, 'author' => 2])));
        self::assertTrue($ability->can('read', Subject::subject('article', ['published' => false, 'author' => 1])));
        self::assertTrue($ability->can('read', Subject::subject('article', ['published' => false, 'author' => 1]), 'secret'));
        self::assertFalse($ability->can('read', Subject::subject('article', ['published' => true, 'author' => 2]), 'secret'));
        self::assertTrue($ability->can('update', 'article', 'title'));
        self::assertFalse($ability->can('update', 'article', 'locked'));
        self::assertTrue($ability->cannot('update', 'article', 'locked'));
        self::assertTrue($ability->can('delete', 'comment'));
        self::assertFalse($ability->can('delete', 'article'));
        self::assertTrue($ability->can('read', Subject::subject('article', (object) ['published' => true])));
    }

    public function testRulesForAndRelevantRuleFor(): void
    {
        $ability = self::ability();

        self::assertCount(2, $ability->rulesFor('read', 'article'));
        self::assertCount(1, $ability->rulesFor('read', 'article', 'secret'));
        self::assertCount(2, $ability->possibleRulesFor('update', 'article'));
        // inverted rules with fields are skipped when no field is asked for
        self::assertCount(1, $ability->rulesFor('update', 'article'));
        // highest priority first
        self::assertSame(['secret'], $ability->rulesFor('read', 'article')[0]->fields);
        self::assertSame(['locked'], $ability->relevantRuleFor('update', 'article', 'locked')?->fields);
        self::assertTrue($ability->relevantRuleFor('update', 'article', 'locked')?->inverted);
        self::assertNull($ability->relevantRuleFor('nope', 'article'));
        self::assertSame('manage', $ability->relevantRuleFor('delete', 'comment')?->action);
    }

    public function testPermittedFieldsOf(): void
    {
        $ability = self::ability();

        self::assertSame(['title', 'body', 'secret'], $ability->permittedFieldsOf('read', 'article'));
        self::assertSame(['title', 'body'], $ability->permittedFieldsOf('read', Subject::subject('article', ['published' => true, 'author' => 2])));
        self::assertSame(['secret'], $ability->permittedFieldsOf('read', Subject::subject('article', ['published' => false, 'author' => 1])));
        self::assertSame([], $ability->permittedFieldsOf('update', 'article'));
        self::assertSame(['a', 'b'], $ability->permittedFieldsOf('update', 'article', static fn (Rule $rule): array => $rule->inverted ? ['c'] : ['a', 'b', 'c']));
    }

    public function testRulesToQuery(): void
    {
        $ability = self::ability();

        // highest priority (last registered) first, as CASL
        self::assertSame(['$or' => [['author' => 1], ['published' => true]]], $ability->rulesToQuery('read', 'article'));
        self::assertSame([], $ability->rulesToQuery('update', 'article'));
        self::assertNull($ability->rulesToQuery('delete', 'article'));
        self::assertSame(['$or' => [['x' => ['author' => 1]], ['x' => ['published' => true]]]], $ability->rulesToQuery('read', 'article', static fn (Rule $rule): array => ['x' => $rule->conditions]));

        // an inverted conditional rule lands under $and, converted by the callback (CASL leaves negation to it)
        $builder = new AbilityBuilder();
        $builder->can('read', 'article');
        $builder->cannot('read', 'article', null, ['locked' => true]);
        self::assertSame(['$and' => [['$not' => ['locked' => true]]]], $builder->build()->rulesToQuery('read', 'article', static fn (Rule $rule): array => $rule->inverted ? ['$not' => $rule->conditions] : $rule->conditions));
        self::assertSame(['$and' => [['locked' => true]]], $builder->build()->rulesToQuery('read', 'article'));
    }

    public function testFieldPatterns(): void
    {
        self::assertTrue(Rule::fieldMatches('*', 'title'));
        self::assertFalse(Rule::fieldMatches('*', 'title.nested'));
        self::assertTrue(Rule::fieldMatches('**', 'title.nested'));
        self::assertTrue(Rule::fieldMatches('meta.*', 'meta.title'));
        self::assertFalse(Rule::fieldMatches('meta.*', 'meta.title.x'));
        self::assertTrue(Rule::fieldMatches('meta.**', 'meta.title.x'));
        self::assertFalse(Rule::fieldMatches('title', 'name'));
    }

    public function testSubjectHelpers(): void
    {
        self::assertSame('article', Subject::detectSubjectType('article'));
        self::assertSame('article', Subject::detectSubjectType(Subject::subject('article', ['id' => 1])));
        self::assertSame('api::article.article', Subject::detectSubjectType(['uid' => 'api::article.article']));
        self::assertNull(Subject::detectSubjectType(['id' => 1]));
        $typed = Subject::subject('article', new \ArrayObject(['id' => 1]));
        self::assertSame('article', Subject::detectSubjectType($typed));
        self::assertInstanceOf(\ArrayObject::class, Subject::entity($typed));
        $std = Subject::subject('article', (object) ['id' => 1]);
        self::assertSame('article', Subject::detectSubjectType($std));
    }

    public function testCaslAbilityBuilderAndParametrizedActions(): void
    {
        $builder = CaslAbility::caslAbilityBuilder();
        $builder->can(['action' => ['name' => 'read', 'params' => ['locale' => 'en', 'ids' => [1, 2]]], 'subject' => 'article']);
        $builder->can(['action' => 'write', 'subject' => null, 'properties' => ['fields' => ['*']], 'condition' => ['$and' => [['$or' => [['id' => 1]]]]]]);
        $ability = $builder->build();

        self::assertSame('read?locale=en&ids%5B0%5D=1&ids%5B1%5D=2', CaslAbility::buildParametrizedAction(['name' => 'read', 'params' => ['locale' => 'en', 'ids' => [1, 2]]]));
        self::assertTrue($ability->can(['name' => 'read', 'params' => ['locale' => 'en', 'ids' => [1, 2]]], 'article'));
        self::assertTrue($ability->can('write', 'all', 'title'));
        self::assertSame(['$and' => [['$or' => [['id' => 1]]]]], $ability->rules()[1]->conditions);
        self::assertTrue(CaslAbility::conditionsMatcher(['id' => ['$in' => [1]]])(['id' => 1]));
    }
}
