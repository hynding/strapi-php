<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\UsersPermissions\Utils\RefreshCookieOptions;

/** Port of server/src/utils/__tests__/refresh-cookie-options.test.js. */
final class RefreshCookieOptionsTest extends TestCase
{
    public function testForwardsMaxAgeWhenConfigured(): void
    {
        $options = RefreshCookieOptions::buildRefreshCookieOptions(['cookie' => ['maxAge' => 120000, 'secure' => false]], false);

        self::assertSame(120000, $options['maxAge']);
    }

    public function testLeavesMaxAgeUndefinedWhenNotConfigured(): void
    {
        $options = RefreshCookieOptions::buildRefreshCookieOptions(['cookie' => ['secure' => false]], false);

        self::assertNull($options['maxAge']);
    }

    public function testAppliesCookieDefaultsAndSecureFallbackFromEnvironment(): void
    {
        $options = RefreshCookieOptions::buildRefreshCookieOptions(['cookie' => []], true);

        self::assertTrue($options['httpOnly']);
        self::assertTrue($options['secure']);
        self::assertSame('lax', $options['sameSite']);
        self::assertSame('/', $options['path']);
        self::assertTrue($options['overwrite']);

        self::assertFalse(RefreshCookieOptions::buildRefreshCookieOptions(['cookie' => []], false)['secure']);
    }

    public function testRespectsExplicitCookieAttributeOverrides(): void
    {
        $options = RefreshCookieOptions::buildRefreshCookieOptions([
            'cookie' => [
                'secure' => false,
                'sameSite' => 'strict',
                'path' => '/api',
                'domain' => 'example.com',
                'maxAge' => 60000,
            ],
        ], true);

        self::assertFalse($options['secure']);
        self::assertSame('strict', $options['sameSite']);
        self::assertSame('/api', $options['path']);
        self::assertSame('example.com', $options['domain']);
        self::assertSame(60000, $options['maxAge']);
    }
}
