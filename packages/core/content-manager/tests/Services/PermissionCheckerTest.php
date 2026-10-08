<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Rule;

/**
 * Port of server/src/services/__tests__/permission-checker.test.ts, on a booted app's admin
 * permission service and real abilities (upstream mocks both).
 */
final class PermissionCheckerTest extends TestCase
{
    private static Strapi $strapi;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    private static function checker(Ability $ability): PermissionChecker
    {
        return (new PermissionChecker(self::$strapi))->create(['userAbility' => $ability, 'model' => 'api::article.article']);
    }

    public function testRequiresEntityIsTrueWhenRulesHaveConditions(): void
    {
        $ability = new Ability([
            new Rule(PermissionChecker::ACTIONS['read'], 'api::article.article', null, ['locale' => 'en']),
        ]);

        self::assertTrue(self::checker($ability)->requiresEntity('read'));
    }

    public function testRequiresEntityIsFalseWhenRulesHaveNoConditions(): void
    {
        $ability = new Ability([
            new Rule(PermissionChecker::ACTIONS['read'], 'api::article.article', null, []),
            new Rule(PermissionChecker::ACTIONS['read'], 'api::article.article'),
        ]);

        self::assertFalse(self::checker($ability)->requiresEntity('read'));
    }

    public function testCanAndCannotUseTheActionShortcuts(): void
    {
        $ability = new Ability([
            new Rule(PermissionChecker::ACTIONS['read'], 'api::article.article', ['title']),
        ]);
        $checker = self::checker($ability);

        self::assertTrue($checker->can('read'));
        self::assertTrue($checker->can('read', null, 'title'));
        self::assertFalse($checker->can('read', null, 'content'));
        self::assertTrue($checker->cannot('create'));
        self::assertFalse($checker->cannot(PermissionChecker::ACTIONS['read']));
    }
}
