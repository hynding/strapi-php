<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Permission\PermissionsManager\Sanitize;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;

/** Port of server/src/services/__tests__/permissions-manager-sanitize.test.ts. */
final class PermissionsManagerSanitizeTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public const array FOO_MODEL = [
        'attributes' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'password'],
            'c' => ['type' => 'string'],
        ],
        'config' => ['attributes' => ['a' => ['hidden' => true]]],
    ];

    public const array ADMIN_USER_MODEL = [
        'attributes' => [
            'id' => ['type' => 'integer'],
            'firstname' => ['type' => 'string'],
            'lastname' => ['type' => 'string'],
            'username' => ['type' => 'string'],
            'email' => ['type' => 'email'],
            'isActive' => ['type' => 'boolean'],
            'password' => ['type' => 'password'],
            'resetPasswordToken' => ['type' => 'string'],
            'registrationToken' => ['type' => 'string'],
            'blocked' => ['type' => 'boolean'],
        ],
        'config' => ['attributes' => []],
    ];

    public const array ARTICLE_MODEL = [
        'attributes' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'content' => ['type' => 'text'],
            'createdBy' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'],
            'updatedBy' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'],
        ],
        'config' => ['attributes' => []],
    ];

    public static function setUpBeforeClass(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'api::foo.foo' => self::FOO_MODEL,
            'admin::user' => self::ADMIN_USER_MODEL,
            'api::article.article' => self::ARTICLE_MODEL,
        ]);
        self::$strapi = $strapi;
    }

    private static function helpers(string $model): Sanitize
    {
        $ability = (new AbilityBuilder())->can('read', $model)->build();

        return new Sanitize(self::$strapi ?? throw new \LogicException(), $ability, 'read', $model);
    }

    /** @param array<string, mixed> $query */
    private static function articleQuery(array $query): mixed
    {
        return self::helpers('api::article.article')->sanitizeQuery($query, ['subject' => 'api::article.article']);
    }

    public function testSanitizeOutputRemovesHiddenFields(): void
    {
        self::assertEquals(['c' => 'Bar'], self::helpers('api::foo.foo')->sanitizeOutput(['a' => 'Foo', 'c' => 'Bar'], ['subject' => 'api::foo.foo']));
    }

    public function testSanitizeInputRemovesHiddenFields(): void
    {
        self::assertEquals(['c' => 'Bar'], self::helpers('api::foo.foo')->sanitizeInput(['a' => 'Foo', 'c' => 'Bar'], ['subject' => 'api::foo.foo']));
    }

    public function testSanitizeQueryRemovesHiddenFields(): void
    {
        $result = self::helpers('api::foo.foo')->sanitizeQuery([
            'filters' => ['a' => 'Foo', 'c' => 'Bar'],
            'sort' => ['a' => 'asc', 'c' => 'desc'],
            'populate' => ['a' => true, 'c' => true],
            'fields' => ['a', 'c'],
        ], ['subject' => 'api::foo.foo']);

        self::assertIsArray($result);
        self::assertEquals(['c' => 'Bar'], $result['filters']);
        self::assertEquals(['c' => 'desc'], $result['sort']);
        self::assertEquals(['c' => true], $result['populate']);
        // upstream: `[undefined, 'c']` (the removed entry leaves a hole)
        self::assertSame(['c'], array_values(array_filter($result['fields'], static fn (mixed $f): bool => $f !== null)));
    }

    public function testFiltersRemovePasswordFromCreatedBy(): void
    {
        $result = self::articleQuery(['filters' => ['createdBy' => ['password' => ['$startsWith' => '$2b$'], 'firstname' => 'John']]]);

        self::assertEquals(['createdBy' => ['firstname' => 'John']], $result['filters']);
    }

    public function testFiltersRemoveResetPasswordTokenFromUpdatedBy(): void
    {
        $result = self::articleQuery(['filters' => ['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc'], 'lastname' => 'Doe']]]);

        self::assertEquals(['updatedBy' => ['lastname' => 'Doe']], $result['filters']);
    }

    public function testFiltersRemoveRegistrationToken(): void
    {
        $result = self::articleQuery(['filters' => ['createdBy' => ['registrationToken' => ['$contains' => 'token']]]]);

        self::assertEquals([], $result['filters']);
    }

    public function testFiltersRemoveBlocked(): void
    {
        $result = self::articleQuery(['filters' => ['updatedBy' => ['blocked' => true, 'firstname' => 'Jane']]]);

        self::assertEquals(['updatedBy' => ['firstname' => 'Jane']], $result['filters']);
    }

    public function testFiltersKeepAllowedFields(): void
    {
        $result = self::articleQuery(['filters' => ['createdBy' => ['firstname' => 'John', 'lastname' => 'Doe']]]);

        self::assertEquals(['createdBy' => ['firstname' => 'John', 'lastname' => 'Doe']], $result['filters']);
    }

    public function testFiltersKeepOnlyAllowedFields(): void
    {
        $result = self::articleQuery(['filters' => ['updatedBy' => [
            'password' => ['$startsWith' => '$2b$'],
            'resetPasswordToken' => ['$contains' => 'reset'],
            'registrationToken' => ['$contains' => 'reg'],
            'blocked' => true,
            'firstname' => 'John',
            'lastname' => 'Doe',
        ]]]);

        self::assertEquals(['updatedBy' => ['firstname' => 'John', 'lastname' => 'Doe']], $result['filters']);
    }

    public function testSortRemovesPassword(): void
    {
        $result = self::articleQuery(['sort' => ['createdBy' => ['password' => 'asc', 'firstname' => 'desc']]]);

        self::assertEquals(['createdBy' => ['firstname' => 'desc']], $result['sort']);
    }

    public function testSortRemovesResetPasswordToken(): void
    {
        $result = self::articleQuery(['sort' => ['updatedBy' => ['resetPasswordToken' => 'desc']]]);

        // upstream leaves `updatedBy: undefined` (ignored by toEqual); the PHP traversal leaves `null`
        self::assertEquals([], array_filter($result['sort'], static fn (mixed $v): bool => $v !== null));
    }

    public function testSortKeepsAllowedFields(): void
    {
        $result = self::articleQuery(['sort' => ['createdBy' => ['firstname' => 'asc']]]);

        self::assertEquals(['createdBy' => ['firstname' => 'asc']], $result['sort']);
    }

    public function testPopulateFiltersRemoveSensitiveFields(): void
    {
        $result = self::articleQuery(['populate' => ['createdBy' => ['filters' => ['password' => ['$startsWith' => '$2b$'], 'firstname' => 'John']]]]);

        self::assertEquals(['firstname' => 'John'], $result['populate']['createdBy']['filters']);
    }

    public function testPopulateFiltersRemoveDisallowedAdminUserFields(): void
    {
        $result = self::articleQuery(['populate' => ['updatedBy' => ['filters' => ['resetPasswordToken' => ['$contains' => 'token'], 'blocked' => true]]]]);

        self::assertEquals([], $result['populate']['updatedBy']['filters']);
    }

    public function testOutputRemovesSensitiveFieldsFromCreatedBy(): void
    {
        $result = self::helpers('api::article.article')->sanitizeOutput([
            'id' => 1,
            'title' => 'Test Article',
            'createdBy' => [
                'id' => 1,
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => 'john@example.com',
                'password' => '$2b$hashedpassword',
                'resetPasswordToken' => 'secret-token',
                'registrationToken' => 'reg-token',
                'blocked' => false,
                'isActive' => true,
            ],
        ], ['subject' => 'api::article.article']);

        self::assertEquals(['id' => 1, 'firstname' => 'John', 'lastname' => 'Doe', 'email' => 'john@example.com', 'isActive' => true], $result['createdBy']);
    }

    public function testOutputRemovesSensitiveFieldsFromUpdatedBy(): void
    {
        $result = self::helpers('api::article.article')->sanitizeOutput([
            'id' => 1,
            'title' => 'Test Article',
            'updatedBy' => [
                'id' => 2,
                'firstname' => 'Jane',
                'lastname' => 'Smith',
                'username' => 'jsmith',
                'email' => 'jane@example.com',
                'password' => '$2b$anotherhashedpassword',
                'resetPasswordToken' => null,
                'blocked' => true,
                'isActive' => false,
            ],
        ], ['subject' => 'api::article.article']);

        self::assertEquals(['id' => 2, 'firstname' => 'Jane', 'lastname' => 'Smith', 'username' => 'jsmith', 'email' => 'jane@example.com', 'isActive' => false], $result['updatedBy']);
    }
}
