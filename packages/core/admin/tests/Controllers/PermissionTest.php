<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../StubStrapi.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\Permission;
use Strapi\Admin\Services\Permission as PermissionService;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/__tests__/permission.test.ts. */
final class PermissionTest extends TestCase
{
    private static function strapi(): Strapi
    {
        $strapi = StubStrapi::create();
        StubStrapi::setService($strapi, 'admin::permission', new PermissionService($strapi));

        return $strapi;
    }

    /** @param array<string, mixed> $body */
    private static function context(array $body): Context
    {
        $ctx = new Context(new ServerRequest('POST', '/admin/permissions/check'));
        $ctx->setRequestBody($body);
        $ctx->state()->set('userAbility', (new AbilityBuilder())->can('read', 'all')->build());

        return $ctx;
    }

    /** @return array<string, array{array<string, mixed>, string, list<string>}> */
    public static function invalidCases(): array
    {
        return [
            'bad type for action' => [['action' => new \stdClass(), 'subject' => '', 'field' => ''], 'permissions[0].action must be a `string` type, but the final value was: `{}`.', ['permissions', '0', 'action']],
            'missing required action' => [['subject' => 'article', 'field' => 'title'], 'permissions[0].action is a required field', ['permissions', '0', 'action']],
            'bad type for subject' => [['action' => 'read', 'subject' => new \stdClass(), 'field' => 'title'], 'permissions[0].subject must be a `string` type, but the final value was: `{}`.', ['permissions', '0', 'subject']],
            'bad type for field' => [['action' => 'read', 'subject' => 'article', 'field' => new \stdClass()], 'permissions[0].field must be a `string` type, but the final value was: `{}`.', ['permissions', '0', 'field']],
            'unrecognized foo param' => [['action' => 'read', 'subject' => 'article', 'field' => 'title', 'foo' => 'bar'], 'permissions[0] field has unspecified keys: foo', ['permissions', '0']],
        ];
    }

    /**
     * @param array<string, mixed> $permission
     * @param list<string> $path
     */
    #[DataProvider('invalidCases')]
    public function testInvalidPermissionShape(array $permission, string $message, array $path): void
    {
        try {
            (new Permission(self::strapi()))->check(self::context(['permissions' => [$permission]]));
            self::fail('Expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('ValidationError', $e->name);
            self::assertSame($message, $e->getMessage());
            self::assertSame($path, $e->details['errors'][0]['path']);
            self::assertSame($message, $e->details['errors'][0]['message']);
            self::assertSame('ValidationError', $e->details['errors'][0]['name']);
        }
    }

    public function testCheckManyPermissions(): void
    {
        $ctx = self::context(['permissions' => [
            ['action' => 'read', 'subject' => 'article', 'field' => 'title'],
            ['action' => 'read', 'subject' => 'article'],
            ['action' => 'read'],
        ]]);

        (new Permission(self::strapi()))->check($ctx);

        self::assertSame(['data' => [true, true, true]], $ctx->body());
    }
}
