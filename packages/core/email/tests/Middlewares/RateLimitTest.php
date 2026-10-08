<?php

declare(strict_types=1);

namespace Strapi\Email\Tests\Middlewares;

require_once dirname(__DIR__) . '/EmailTestCase.php';

use Strapi\Admin\Middlewares\RateLimit;
use Strapi\Email\Tests\EmailTestCase;
use Strapi\Utils\Errors\RateLimitError;

/** middlewares/rateLimit.ts (no upstream unit test). */
final class RateLimitTest extends EmailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimit::reset();
    }

    protected function tearDown(): void
    {
        self::strapi()->config()->set('plugin::email.ratelimit', null);
        RateLimit::reset();
        parent::tearDown();
    }

    public function testFiveRequestsPerEmailThenRateLimitError(): void
    {
        $middleware = self::strapi()->middleware('plugin::email.rateLimit')([], self::strapi());
        $next = static fn (): string => 'next';

        for ($i = 0; $i < 5; $i++) {
            self::assertSame('next', $middleware(self::ctx('POST', '/admin/forgot-password', ['email' => 'Jane@Example.com']), $next));
        }
        // another email has its own budget
        self::assertSame('next', $middleware(self::ctx('POST', '/admin/forgot-password', ['email' => 'other@example.com']), $next));

        $this->expectException(RateLimitError::class);
        $middleware(self::ctx('POST', '/admin/forgot-password', ['email' => 'jane@example.com']), $next);
    }

    public function testRouteConfigOverridesTheDefaults(): void
    {
        $middleware = self::strapi()->middleware('plugin::email.rateLimit')(['max' => 1], self::strapi());
        $next = static fn (): string => 'next';

        self::assertSame('next', $middleware(self::ctx('POST', '/x', ['email' => 'a@b.c']), $next));
        $this->expectException(RateLimitError::class);
        $middleware(self::ctx('POST', '/x', ['email' => 'a@b.c']), $next);
    }

    public function testCanBeDisabledFromThePluginConfig(): void
    {
        self::strapi()->config()->set('plugin::email.ratelimit', ['enabled' => false]);
        $middleware = self::strapi()->middleware('plugin::email.rateLimit')([], self::strapi());

        for ($i = 0; $i < 10; $i++) {
            self::assertSame('next', $middleware(self::ctx('POST', '/x', ['email' => 'a@b.c']), static fn (): string => 'next'));
        }
    }

    public function testTheAdminForgotPasswordRouteUsesIt(): void
    {
        /** @var list<array<string, mixed>> $routes */
        $routes = require dirname(__DIR__, 4) . '/core/admin/server/src/routes/authentication.php';
        $forgotPassword = array_values(array_filter($routes, static fn (array $r): bool => $r['path'] === '/forgot-password'));

        self::assertSame(['plugin::email.rateLimit'], $forgotPassword[0]['config']['middlewares']);
        // registered with the admin routes, so the middleware resolved at boot
        $paths = array_map(static fn (array $r): string => (string) ($r['path'] ?? ''), self::strapi()->server()->listRoutes());
        self::assertContains('/admin/forgot-password', $paths);
    }
}
