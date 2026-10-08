<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Shared\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Shared\Utils\AuthCookieName;

/** Port of shared/utils/__tests__/auth-cookie-name.test.ts. */
final class AuthCookieNameTest extends TestCase
{
    public function testDefaultsToJwtTokenForUndefinedEmptyAndBlankValues(): void
    {
        self::assertSame('jwtToken', AuthCookieName::resolveAuthCookieName());
        self::assertSame('jwtToken', AuthCookieName::resolveAuthCookieName(''));
        self::assertSame('jwtToken', AuthCookieName::resolveAuthCookieName('   '));
    }

    public function testReturnsTheConfiguredName(): void
    {
        self::assertSame('my_cookie', AuthCookieName::resolveAuthCookieName('my_cookie'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('my_cookie', AuthCookieName::resolveAuthCookieName('  my_cookie  '));
    }

    public function testFallsBackAndWarnsOnAnInvalidName(): void
    {
        $warnings = [];
        self::assertSame('jwtToken', AuthCookieName::resolveAuthCookieName('bad name;', static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('bad name;', $warnings[0]);
    }
}
