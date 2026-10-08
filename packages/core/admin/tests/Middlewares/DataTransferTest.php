<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Middlewares;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\Transfer\Runner;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotImplementedError;

/** server/src/middlewares/data-transfer.ts and the transfer runner outside `transfer:serve` (no upstream unit test). */
final class DataTransferTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    /** @return array{0: int, 1: mixed, 2: bool} status, body, next called */
    private static function runMiddleware(mixed $enabled, string $salt): array
    {
        $strapi = self::$strapi ?? throw new \LogicException('not booted');
        $strapi->config()->set('server.transfer.remote.enabled', $enabled);
        $strapi->config()->set('admin.transfer.token.salt', $salt);
        $factory = require dirname(__DIR__, 2) . '/server/src/middlewares/data-transfer.php';
        $ctx = BootedAdminApp::ctx();
        $called = false;
        $factory([], $strapi)($ctx, static function () use (&$called): void {
            $called = true;
        });

        return [$ctx->status(), $ctx->body(), $called];
    }

    public function testCallsNextWhenRemoteTransferIsEnabled(): void
    {
        self::assertTrue(self::runMiddleware(true, 'salt')[2]);
    }

    public function testAnswersNotFoundWhenRemoteTransferIsDisabled(): void
    {
        [$status, , $called] = self::runMiddleware(false, 'salt');

        self::assertSame(404, $status);
        self::assertFalse($called);
    }

    public function testAnswersNotImplementedWithoutASalt(): void
    {
        [$status, $body] = self::runMiddleware(true, '');

        self::assertSame(501, $status);
        self::assertSame('The server configuration for data transfer is invalid. Please contact your server administrator.', $body['error']['message']);
        self::assertSame(['code' => 'INVALID_TOKEN_SALT'], $body['error']['details']);
    }

    public function testTheRunnerNeedsTheTransferServerToUpgrade(): void
    {
        // Upstream authenticates the upgrade at the route (`data-transfer` strategy) and verifies the
        // token's scope in the handler, on `init`. Served by the HTTP worker, the runner cannot hold
        // the WebSocket: it answers 501 and points to `strapi transfer:serve`.
        $ctx = BootedAdminApp::ctx();
        $ability = new class () {
            public function can(string $action): bool
            {
                return $action === 'push';
            }
        };
        $ctx->state()->set('auth', ['credentials' => ['id' => 1, 'expiresAt' => null], 'ability' => $ability]);

        $this->expectException(NotImplementedError::class);
        $this->expectExceptionMessage('strapi transfer:serve');
        (new Runner())->push($ctx);
    }
}
