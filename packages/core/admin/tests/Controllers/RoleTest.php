<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../StubStrapi.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\Role;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Admin\Validation\CommonValidators;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Hooks;

/** Port of server/src/controllers/__tests__/role.test.ts. */
final class RoleTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    public static array $calls = [];

    protected function setUp(): void
    {
        self::$calls = [];
    }

    protected function tearDown(): void
    {
        CommonValidators::$serviceResolver = null;
    }

    /**
     * A recording stub: `$methods` maps method names to closures.
     *
     * @param array<string, \Closure> $methods
     * @param array<string, mixed> $props
     */
    private static function stub(string $name, array $methods, array $props = []): object
    {
        return new class ($name, $methods, $props) {
            /**
             * @param array<string, \Closure> $methods
             * @param array<string, mixed> $props
             */
            public function __construct(private readonly string $name, private readonly array $methods, private readonly array $props)
            {
            }

            /** @param list<mixed> $args */
            public function __call(string $method, array $args): mixed
            {
                RoleTest::$calls[] = ["{$this->name}.{$method}", $args];

                return ($this->methods[$method])(...$args);
            }

            public function __get(string $prop): mixed
            {
                return $this->props[$prop] ?? null;
            }
        };
    }

    /** @param array<string, object> $services */
    private static function strapi(array $services): Strapi
    {
        $strapi = StubStrapi::create();
        foreach ($services as $name => $service) {
            StubStrapi::setService($strapi, "admin::{$name}", $service);
        }
        CommonValidators::$serviceResolver = static fn (string $name): object => $strapi->service("admin::{$name}");

        return $strapi;
    }

    /** @param array<string, mixed>|null $body */
    private static function context(?array $body = null): Context
    {
        $ctx = new Context(new ServerRequest('PUT', '/admin/roles/1/permissions'));
        $ctx->setParams(['id' => '1']);
        if ($body !== null) {
            $ctx->setRequestBody($body);
        }

        return $ctx;
    }

    /** @return list<list<mixed>> */
    private static function callsTo(string $name): array
    {
        return array_values(array_map(static fn (array $c): array => $c[1], array_filter(self::$calls, static fn (array $c): bool => $c[0] === $name)));
    }

    public function testGetPermissionsFailsIfRoleDoesNotExist(): void
    {
        $strapi = self::strapi(['role' => self::stub('role', ['findOne' => static fn (): ?array => null])]);
        $ctx = self::context();

        (new Role($strapi))->getPermissions($ctx);

        self::assertSame([[['id' => '1']]], self::callsTo('role.findOne'));
        self::assertSame(404, $ctx->status());
    }

    public function testGetPermissionsFindsPermissions(): void
    {
        $permissions = [['action' => 'test1'], ['action' => 'test2', 'subject' => 'model1']];
        $strapi = self::strapi([
            'role' => self::stub('role', ['findOne' => static fn (): array => ['id' => 1]]),
            'permission' => self::stub('permission', [
                'findMany' => static fn (): array => $permissions,
                'sanitizePermission' => static fn (array $p): array => $p,
            ]),
        ]);
        $ctx = self::context();

        (new Role($strapi))->getPermissions($ctx);

        self::assertSame([[['id' => '1']]], self::callsTo('role.findOne'));
        self::assertSame([[['where' => ['role' => ['id' => 1]]]]], self::callsTo('permission.findMany'));
        self::assertSame(['data' => $permissions], $ctx->body());
    }

    public function testUpdatePermissionsFailsOnMissingPermissionsInput(): void
    {
        $strapi = self::strapi([
            'permission' => self::stub('permission', ['sanitizePermission' => static fn (array $p): array => $p]),
            'role' => self::stub('role', ['findOne' => static fn (): array => ['id' => 1]]),
        ]);

        try {
            (new Role($strapi))->updatePermissions(self::context([]));
            self::fail('Expected an error');
        } catch (ApplicationError $e) {
            self::assertSame('permissions is a required field', $e->getMessage());
        }
    }

    public function testUpdatePermissionsFailsOnMissingAction(): void
    {
        $strapi = self::strapi([
            'role' => self::stub('role', ['findOne' => static fn (): array => ['id' => 1]]),
            'permission' => self::stub('permission', ['sanitizePermission' => static fn (array $p): array => $p], [
                'actionProvider' => self::stub('actionProvider', ['get' => static fn (): ?array => null]),
                'conditionProvider' => self::stub('conditionProvider', ['values' => static fn (): array => []]),
            ]),
        ]);

        try {
            (new Role($strapi))->updatePermissions(self::context(['permissions' => [[]]]));
            self::fail('Expected an error');
        } catch (ApplicationError $e) {
            self::assertSame('permissions[0].action is a required field', $e->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $inputPermissions
     * @param \Closure(mixed): mixed $willValidate
     * @param array<string, mixed> $action
     */
    private static function assignStrapi(array $inputPermissions, \Closure $willValidate, array $action): Strapi
    {
        $hook = Hooks::createAsyncSeriesWaterfallHook();
        $hook->register(static function (mixed $permissions) use ($willValidate): mixed {
            RoleTest::$calls[] = ['willValidateUpdatePermissions', [$permissions]];

            return $willValidate($permissions);
        });

        return self::strapi([
            'role' => self::stub('role', [
                'findOne' => static fn (): array => ['id' => 1],
                'assignPermissions' => static fn (mixed $roleId, array $permissions): array => $permissions,
                'getSuperAdmin' => static fn (): ?array => null,
            ], ['hooks' => ['willValidateUpdatePermissions' => $hook]]),
            'permission' => self::stub('permission', ['sanitizePermission' => static fn (array $p): array => $p], [
                'conditionProvider' => self::stub('conditionProvider', ['values' => static fn (): array => [['id' => 'admin::is-creator']]]),
                'actionProvider' => self::stub('actionProvider', [
                    'values' => static fn (): array => [['actionId' => $action['actionId'], 'subjects' => $action['subjects']]],
                    'get' => static fn (): array => $action,
                ]),
            ]),
        ]);
    }

    public function testAssignPermissionsIfInputIsValid(): void
    {
        $inputPermissions = [[
            'action' => 'test',
            'subject' => 'model1',
            'properties' => ['fields' => ['title']],
            'conditions' => ['admin::is-creator'],
        ]];
        $strapi = self::assignStrapi($inputPermissions, static fn (mixed $p): mixed => $p, [
            'actionId' => 'test',
            'subjects' => ['model1'],
            'options' => ['applyToProperties' => ['fields']],
        ]);
        $ctx = self::context(['permissions' => $inputPermissions]);

        (new Role($strapi))->updatePermissions($ctx);

        self::assertSame([[['id' => '1']]], self::callsTo('role.findOne'));
        self::assertSame([[$inputPermissions]], self::callsTo('willValidateUpdatePermissions'));
        self::assertSame([[1, $inputPermissions]], self::callsTo('role.assignPermissions'));
        self::assertSame(['data' => $inputPermissions], $ctx->body());
    }

    public function testAssignsPermissionsReturnedByWillValidateUpdatePermissions(): void
    {
        $inputPermissions = [[
            'action' => 'plugin::content-manager.explorer.read',
            'subject' => 'api::article.article',
            'properties' => ['fields' => ['title'], 'locales' => []],
            'conditions' => [],
        ]];
        $normalizedPermissions = [[...$inputPermissions[0], 'properties' => ['fields' => ['title'], 'locales' => ['en']]]];
        $strapi = self::assignStrapi($inputPermissions, static fn (): array => $normalizedPermissions, [
            'actionId' => 'plugin::content-manager.explorer.read',
            'subjects' => ['api::article.article'],
            'options' => ['applyToProperties' => ['fields', 'locales']],
        ]);
        $ctx = self::context(['permissions' => $inputPermissions]);

        (new Role($strapi))->updatePermissions($ctx);

        self::assertSame([[$inputPermissions]], self::callsTo('willValidateUpdatePermissions'));
        self::assertSame([[1, $normalizedPermissions]], self::callsTo('role.assignPermissions'));
        self::assertSame(['data' => $normalizedPermissions], $ctx->body());
    }
}
