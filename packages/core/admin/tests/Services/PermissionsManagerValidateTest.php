<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';
require_once __DIR__ . '/PermissionsManagerSanitizeTest.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Permission\PermissionsManager\Validate;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/services/__tests__/permissions-manager-validate.test.ts. */
final class PermissionsManagerValidateTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'api::foo.foo' => PermissionsManagerSanitizeTest::FOO_MODEL,
            'admin::user' => PermissionsManagerSanitizeTest::ADMIN_USER_MODEL,
            'api::article.article' => PermissionsManagerSanitizeTest::ARTICLE_MODEL,
        ]);
        self::$strapi = $strapi;
    }

    private static function helpers(string $model): Validate
    {
        $ability = (new AbilityBuilder())->can('read', $model)->build();

        return new Validate(self::$strapi ?? throw new \LogicException(), $ability, 'read', $model);
    }

    public function testPassesValidInput(): void
    {
        self::assertEquals(['c' => 'Bar'], self::helpers('api::foo.foo')->validateInput(['c' => 'Bar'], ['subject' => 'api::foo.foo']));
    }

    public function testThrowsOnHiddenFields(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key a');

        self::helpers('api::foo.foo')->validateInput(['a' => 'Foo', 'c' => 'Bar'], ['subject' => 'api::foo.foo']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function passwordCases(): array
    {
        return [
            'filters' => [['filters' => ['c' => 'Foo', 'b' => 'Bar']], 'b'],
            'sort' => [['sort' => ['c' => 'Foo', 'b' => 'Bar']], 'b'],
            'fields' => [['fields' => ['c', 'b']], 'b'],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('passwordCases')]
    public function testThrowsOnPassword(array $data, string $invalidParam): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage("Invalid key {$invalidParam}");

        self::helpers('api::foo.foo')->validateQuery($data, ['subject' => 'api::foo.foo']);
    }

    /** @return array<string, array{array<string, mixed>, string|null}> */
    public static function articleCases(): array
    {
        return [
            'filter createdBy.password' => [['filters' => ['createdBy' => ['password' => ['$startsWith' => '$2b$']]]], 'password'],
            'filter updatedBy.resetPasswordToken' => [['filters' => ['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc']]]], 'resetPasswordToken'],
            'filter createdBy.registrationToken' => [['filters' => ['createdBy' => ['registrationToken' => ['$contains' => 'token']]]], 'registrationToken'],
            'filter updatedBy.blocked' => [['filters' => ['updatedBy' => ['blocked' => true]]], 'blocked'],
            'filter createdBy.firstname' => [['filters' => ['createdBy' => ['firstname' => 'John']]], null],
            'filter updatedBy.lastname' => [['filters' => ['updatedBy' => ['lastname' => 'Doe']]], null],
            'sort createdBy.password' => [['sort' => ['createdBy' => ['password' => 'asc']]], 'password'],
            'sort updatedBy.resetPasswordToken' => [['sort' => ['updatedBy' => ['resetPasswordToken' => 'desc']]], 'resetPasswordToken'],
            'sort createdBy.firstname' => [['sort' => ['createdBy' => ['firstname' => 'asc']]], null],
            'populate password filters' => [['populate' => ['createdBy' => ['filters' => ['password' => ['$startsWith' => '$2b$']]]]], 'password'],
            'populate disallowed admin user filters' => [['populate' => ['updatedBy' => ['filters' => ['resetPasswordToken' => ['$contains' => 'token']]]]], 'resetPasswordToken'],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('articleCases')]
    public function testArticleQuery(array $query, ?string $invalidKey): void
    {
        if ($invalidKey !== null) {
            $this->expectException(ValidationError::class);
            $this->expectExceptionMessage("Invalid key {$invalidKey}");
        }

        $result = self::helpers('api::article.article')->validateQuery($query, ['subject' => 'api::article.article']);

        self::assertTrue($result);
    }
}
