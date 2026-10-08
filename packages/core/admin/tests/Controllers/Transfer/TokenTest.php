<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers\Transfer;

require_once __DIR__ . '/../../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\Transfer\Token;
use Strapi\Admin\Controllers\Transfer\Transfer;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Registries\ActionMap;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/__tests__/transfer/token.test.ts, against a booted app. */
final class TokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->config()->set('admin.transfer.token.salt', 'transfer-salt');
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function controller(): Token
    {
        return new Token(self::$strapi ?? throw new \LogicException('not booted'));
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $params */
    private static function ctx(?array $body = null, array $params = []): Context
    {
        $ctx = BootedAdminApp::ctx('POST', '/admin/transfer/tokens', $body);
        if ($body !== null) {
            $ctx->setRequestBody($body);
        }
        $ctx->setParams($params);

        return $ctx;
    }

    /** @return array<string, mixed> */
    private static function create(array $body): array
    {
        $ctx = self::ctx($body);
        self::controller()->create($ctx);
        self::assertSame(201, $ctx->status());

        return $ctx->body()['data'];
    }

    public function testTheTransferControllerPrefixesItsActions(): void
    {
        $controller = Transfer::create(self::$strapi ?? throw new \LogicException('not booted'));

        self::assertInstanceOf(ActionMap::class, $controller);
        self::assertEqualsCanonicalizing(
            ['runner-push', 'runner-pull', 'token-list', 'token-getById', 'token-create', 'token-update', 'token-revoke', 'token-regenerate'],
            ActionMap::actionNames($controller),
        );
    }

    public function testCreateTransferTokenSuccessfullyIgnoringAReceivedExpiresAt(): void
    {
        $data = self::create(['name' => ' transfer ', 'description' => ' d ', 'permissions' => ['push'], 'expiresAt' => 1234]);

        self::assertSame('transfer', $data['name']);
        self::assertSame('d', $data['description']);
        self::assertSame(['push'], $data['permissions']);
        self::assertNull($data['expiresAt']);
    }

    public function testFailsIfTransferTokenAlreadyExists(): void
    {
        self::create(['name' => 'dup', 'permissions' => ['push']]);

        $this->expectExceptionObject(new ApplicationError('Name already taken'));
        self::controller()->create(self::ctx(['name' => 'dup', 'permissions' => ['push']]));
    }

    public function testThrowsWithInvalidOrNegativeLifespan(): void
    {
        foreach ([1234, -1] as $lifespan) {
            try {
                self::controller()->create(self::ctx(['name' => "l{$lifespan}", 'permissions' => ['push'], 'lifespan' => $lifespan]));
                self::fail('expected a ValidationError');
            } catch (ValidationError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testListGetUpdateRegenerateAndRevoke(): void
    {
        $token = self::create(['name' => 'lifecycle', 'permissions' => ['push']]);
        $id = (string) $token['id'];

        $ctx = self::ctx();
        self::controller()->list($ctx);
        self::assertContains($token['id'], array_column($ctx->body()['data'], 'id'));

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->getById($ctx);
        self::assertSame($token['id'], $ctx->body()['data']['id']);

        $ctx = self::ctx(['name' => ' renamed ', 'permissions' => ['pull']], ['id' => $id]);
        self::controller()->update($ctx);
        self::assertSame('renamed', $ctx->body()['data']['name']);
        self::assertSame(['pull'], $ctx->body()['data']['permissions']);

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->regenerate($ctx);
        self::assertSame(201, $ctx->status());

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->revoke($ctx);
        self::assertSame($token['id'], $ctx->body()['data']['id']);

        foreach (['getById', 'regenerate'] as $action) {
            $ctx = self::ctx(null, ['id' => $id]);
            self::controller()->{$action}($ctx);
            self::assertSame(404, $ctx->status());
            self::assertSame('Transfer token not found', $ctx->body()['error']['message']);
        }
    }

    public function testUpdateFailsIfTheNameIsAlreadyTaken(): void
    {
        self::create(['name' => 'taken', 'permissions' => ['push']]);
        $other = self::create(['name' => 'other', 'permissions' => ['push']]);

        $this->expectExceptionObject(new ApplicationError('Name already taken'));
        self::controller()->update(self::ctx(['name' => 'taken'], ['id' => (string) $other['id']]));
    }
}
