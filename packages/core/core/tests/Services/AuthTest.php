<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Auth\Auth;
use Strapi\Core\Services\Server\Context;
use Strapi\Utils\Errors\UnauthorizedError;

/** The PHP-port fallback when no strategy is registered: public content API, 401 admin routes. */
final class AuthTest extends TestCase
{
    /** @param array<string, mixed> $route */
    private static function ctx(array $route): Context
    {
        $ctx = new Context((new Psr17Factory())->createServerRequest('GET', 'http://localhost/anything'));
        $ctx->state()->set('route', $route);

        return $ctx;
    }

    public function testContentApiIsPublicWhenNoStrategyIsRegistered(): void
    {
        $ctx = self::ctx(['info' => ['type' => 'content-api'], 'config' => []]);
        $called = false;

        Auth::createAuthentication()->authenticate($ctx, static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
        self::assertNull(Auth::createAuthentication()->verify(null, [], 'content-api'));
    }

    public function testAdminRoutesAre401WhenNoStrategyIsRegistered(): void
    {
        $ctx = self::ctx(['info' => ['type' => 'admin', 'pluginName' => 'example'], 'config' => []]);
        $called = false;

        Auth::createAuthentication()->authenticate($ctx, static function () use (&$called): void {
            $called = true;
        });

        self::assertFalse($called);
        self::assertSame(401, $ctx->status());
        self::assertSame('UnauthorizedError', $ctx->body()['error']['name'] ?? null);

        $this->expectException(UnauthorizedError::class);
        Auth::createAuthentication()->verify(null, [], 'admin');
    }

    public function testAuthFalseIsAlwaysPublic(): void
    {
        $ctx = self::ctx(['info' => ['type' => 'admin'], 'config' => ['auth' => false]]);
        $called = false;

        Auth::createAuthentication()->authenticate($ctx, static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }
}
