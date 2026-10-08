<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Server;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Services\Server\Cookies;

/** `ctx.cookies` (the `cookies` npm module as Koa builds it) and `ctx.request.secure`. */
final class CookiesTest extends TestCase
{
    /** @param array<string, string> $headers @param array{proxy?: bool, keys?: list<string>|null} $options */
    private static function ctx(array $headers = [], array $options = [], string $uri = 'http://localhost/admin'): Context
    {
        return new Context(new ServerRequest('GET', $uri, $headers), $options);
    }

    public function testSecureHonoursForwardedProtoOnlyBehindATrustedProxy(): void
    {
        self::assertFalse(self::ctx(['X-Forwarded-Proto' => 'https'])->secure());
        self::assertTrue(self::ctx(['X-Forwarded-Proto' => 'https, http'], ['proxy' => true])->secure());
        self::assertFalse(self::ctx([], ['proxy' => true])->secure());
        self::assertTrue(self::ctx([], [], 'https://localhost/admin')->secure());
    }

    public function testGetReadsTheRawValueWithoutOptions(): void
    {
        $ctx = self::ctx(['Cookie' => 'a=1; strapi_admin_refresh=tok"en; b=2'], ['keys' => ['k']]);

        self::assertSame('tok"en', $ctx->cookies()->get('strapi_admin_refresh'));
        self::assertNull($ctx->cookies()->get('missing'));
    }

    public function testGetWithOptionsChecksTheSignature(): void
    {
        $sig = Cookies::sign('name=value', 'k');
        self::assertSame('value', self::ctx(['Cookie' => "name=value; name.sig={$sig}"], ['keys' => ['k']])->cookies()->get('name', []));
        self::assertNull(self::ctx(['Cookie' => 'name=value; name.sig=forged'], ['keys' => ['k']])->cookies()->get('name', []));
        self::assertNull(self::ctx(['Cookie' => 'name=value'], ['keys' => ['k']])->cookies()->get('name', []));
    }

    public function testSetSerializesLikeTheCookiesModuleAndSigns(): void
    {
        $ctx = self::ctx([], ['keys' => ['k']]);

        $ctx->cookies()->set('strapi_admin_refresh', 'token', ['httpOnly' => true, 'secure' => false, 'overwrite' => true, 'domain' => null, 'path' => '/admin', 'sameSite' => 'lax', 'maxAge' => null]);

        self::assertSame([
            'strapi_admin_refresh=token; path=/admin; samesite=lax; httponly',
            'strapi_admin_refresh.sig=' . Cookies::sign('strapi_admin_refresh=token', 'k') . '; path=/admin; samesite=lax; httponly',
        ], $ctx->responseHeaders()['set-cookie']);
    }

    public function testOverwriteReplacesAPreviousSetCookie(): void
    {
        $ctx = self::ctx();
        $ctx->cookies()->set('a', '1', ['overwrite' => true]);
        $ctx->cookies()->set('a', '2', ['overwrite' => true]);

        self::assertSame(['a=2; path=/; httponly'], $ctx->responseHeaders()['set-cookie']);
    }

    public function testAnEmptyValueExpiresTheCookieAndMaxAgeSetsExpires(): void
    {
        $ctx = self::ctx();
        $ctx->cookies()->set('a', '', ['path' => '/admin']);
        $ctx->cookies()->set('b', 'v', ['maxAge' => 60_000, 'secure' => false]);

        $headers = $ctx->responseHeaders()['set-cookie'];
        self::assertSame('a=; path=/admin; expires=Thu, 01 Jan 1970 00:00:00 GMT; httponly', $headers[0]);
        self::assertMatchesRegularExpression('/^b=v; path=\/; expires=\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT; httponly$/', $headers[1]);
    }

    public function testASecureCookieNeedsASecureRequest(): void
    {
        self::ctx(['X-Forwarded-Proto' => 'https'], ['proxy' => true])->cookies()->set('a', '1', ['secure' => true]);

        $this->expectExceptionMessage('Cannot send secure cookie over unencrypted connection');
        self::ctx()->cookies()->set('a', '1', ['secure' => true]);
    }
}
