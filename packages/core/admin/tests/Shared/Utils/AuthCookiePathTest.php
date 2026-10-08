<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Shared\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Shared\Utils\AuthCookiePath;

/** Port of shared/utils/__tests__/auth-cookie-path.test.ts. */
final class AuthCookiePathTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private function resolve(?string $path): string
    {
        return AuthCookiePath::resolveAuthCookiePath($path, function (string $m): void {
            $this->warnings[] = $m;
        });
    }

    public function testDefaultsToAdminWhenUnsetOrBlank(): void
    {
        self::assertSame(AuthCookiePath::DEFAULT_AUTH_COOKIE_PATH, $this->resolve(null));
        self::assertSame('/admin', $this->resolve(''));
        self::assertSame('/admin', $this->resolve('   '));
    }

    public function testReturnsAConfiguredAbsolutePath(): void
    {
        self::assertSame('/strapi-de/admin', $this->resolve('/strapi-de/admin'));
        self::assertSame('/', $this->resolve('/'));
    }

    public function testRejectsRelativeOrInvalidPaths(): void
    {
        self::assertSame('/admin', $this->resolve('admin'));
        self::assertSame('/admin', $this->resolve('/admin;HttpOnly'));
        self::assertNotEmpty($this->warnings);
    }
}
