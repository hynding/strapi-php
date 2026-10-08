<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Middlewares;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Plugin\UsersPermissions\Middlewares\RateLimit;
use Strapi\Utils\Errors\RateLimitError;

/** Port of server/src/middlewares/__tests__/rateLimit.test.js. */
final class RateLimitTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private static function makeCtx(string $path, string $ip = '203.0.113.1', array $body = []): Context
    {
        $request = (new ServerRequest('POST', 'http://localhost' . $path, [], null, '1.1', ['REMOTE_ADDR' => $ip]))->withParsedBody($body);

        return new Context($request);
    }

    // buildPrefixKey — routes that use email as a legitimate identifier

    public function testIncludesLowerCasedEmailFromBodyForRegister(): void
    {
        $ctx = self::makeCtx('/api/auth/local/register', body: ['email' => 'User@Example.com']);

        self::assertSame('user@example.com:/api/auth/local/register:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    public function testIncludesEmailFromBodyForForgotPassword(): void
    {
        $ctx = self::makeCtx('/api/auth/forgot-password', body: ['email' => 'victim@example.com']);

        self::assertSame('victim@example.com:/api/auth/forgot-password:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    public function testFallsBackToUnknownIdentifierWhenEmailIsMissing(): void
    {
        self::assertSame('unknownIdentifier:/api/auth/forgot-password:203.0.113.1', RateLimit::buildPrefixKey(self::makeCtx('/api/auth/forgot-password')));
    }

    public function testFallsBackToUnknownIdentifierWhenEmailIsNotAString(): void
    {
        $ctx = self::makeCtx('/api/auth/forgot-password', body: ['email' => ['nested' => true]]);

        self::assertSame('unknownIdentifier:/api/auth/forgot-password:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    public function testFallsBackToUnknownIdentifierWhenEmailIsANumber(): void
    {
        $ctx = self::makeCtx('/api/auth/forgot-password', body: ['email' => 99]);

        self::assertSame('unknownIdentifier:/api/auth/forgot-password:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    // routes that must not include the email identifier (GHSA-7mqx-wwh4-f9fw)

    /** @return iterable<string, array{string}> */
    public static function routesWithoutIdentifier(): iterable
    {
        foreach (RateLimit::ROUTES_WITHOUT_IDENTIFIER as $route) {
            yield $route => [$route];
        }
    }

    #[DataProvider('routesWithoutIdentifier')]
    public function testUsesNoIdentifierPrefixRegardlessOfBodyEmail(string $route): void
    {
        $baseKey = RateLimit::buildPrefixKey(self::makeCtx($route));

        // An attacker varying body.email must not change the key.
        foreach (['a@a.com', 'b@b.com', '<random>', ''] as $email) {
            self::assertSame($baseKey, RateLimit::buildPrefixKey(self::makeCtx($route, body: ['email' => $email])));
        }

        self::assertSame("noIdentifier:{$route}:203.0.113.1", $baseKey);
    }

    public function testMatchesPathsRegardlessOfRouterMountPrefix(): void
    {
        self::assertSame('noIdentifier:/auth/local:203.0.113.1', RateLimit::buildPrefixKey(self::makeCtx('/auth/local')));
        self::assertSame('noIdentifier:/api/auth/local:203.0.113.1', RateLimit::buildPrefixKey(self::makeCtx('/api/auth/local')));
    }

    public function testTreatsPathsWithATrailingSlashLikeTheirCanonicalForm(): void
    {
        $withSlash = self::makeCtx('/api/auth/local/', body: ['email' => 'a@a.com']);
        $noSlash = self::makeCtx('/api/auth/local', body: ['email' => 'b@b.com']);

        self::assertSame(RateLimit::buildPrefixKey($noSlash), RateLimit::buildPrefixKey($withSlash));
        self::assertSame('noIdentifier:/api/auth/local:203.0.113.1', RateLimit::buildPrefixKey($withSlash));
    }

    public function testTreatsOAuthCallbackPathsAsIdentifierLess(): void
    {
        $ctx = self::makeCtx('/api/connect/google/callback', body: ['email' => 'attacker@example.com']);

        self::assertSame('noIdentifier:/api/connect/google/callback:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    public function testStripsTrailingSlashesOnConnectPathsForStableKeys(): void
    {
        $a = self::makeCtx('/api/connect/google/callback/', body: ['email' => 'x@x.com']);
        $b = self::makeCtx('/api/connect/google/callback', body: ['email' => 'y@y.com']);

        self::assertSame(RateLimit::buildPrefixKey($b), RateLimit::buildPrefixKey($a));
    }

    // path normalization (.. segments and duplicate slashes)

    public function testClassifiesDotDotSegmentsAsTheLoginRoute(): void
    {
        // a PSR-7 URI keeps `..` segments as sent, like Koa's ctx.request.path
        $ctx = self::makeCtx('/api/auth/reset-password/../local', body: ['email' => 'roll@dice.com']);

        self::assertSame('noIdentifier:/api/auth/local:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    public function testCollapsesDuplicateSlashesBeforeMatchingRoutes(): void
    {
        self::assertSame(
            'noIdentifier:/api/auth/local:203.0.113.1',
            RateLimit::buildPrefixKeyFromRequest('//api//auth//local', '203.0.113.1', ['email' => 'trick@example.com']),
        );
    }

    // input handling

    public function testFallsBackToInvalidPathWhenThePathIsNotAString(): void
    {
        // Conservative default: if we cannot determine the path we cannot
        // verify it is on the no-identifier list, so treat it as a route
        // where the email key applies. The throttle still engages.
        self::assertSame('unknownIdentifier:invalidPath:203.0.113.1', RateLimit::buildPrefixKeyFromRequest(null, '203.0.113.1', []));
    }

    public function testLowercasesTheRequestPath(): void
    {
        $ctx = self::makeCtx('/API/Auth/Forgot-Password', body: ['email' => 'a@b.com']);

        self::assertSame('a@b.com:/api/auth/forgot-password:203.0.113.1', RateLimit::buildPrefixKey($ctx));
    }

    public function testKeysVaryByIpForTheSameEmail(): void
    {
        $a = self::makeCtx('/api/auth/forgot-password', '198.51.100.1', ['email' => 'victim@example.com']);
        $b = self::makeCtx('/api/auth/forgot-password', '198.51.100.2', ['email' => 'victim@example.com']);

        self::assertNotSame(RateLimit::buildPrefixKey($a), RateLimit::buildPrefixKey($b));
    }

    public function testNormalizeRequestPathRemovesTrailingSlashesExceptRoot(): void
    {
        self::assertSame('/api/auth/local', RateLimit::normalizeRequestPathForRateLimit('/api/auth/local/'));
        self::assertSame('/', RateLimit::normalizeRequestPathForRateLimit('/'));
    }

    public function testBuildRateLimitLoadConfigAlwaysSetsPrefixKey(): void
    {
        $ctx = self::makeCtx('/api/auth/local', body: ['email' => 'inject@example.com']);

        $loadConfig = RateLimit::buildRateLimitLoadConfig(
            $ctx,
            ['prefixKey' => 'hijacked-from-strapi-config', 'max' => 42, 'interval' => ['min' => 1]],
            ['prefixKey' => 'hijacked-from-route-config'],
        );

        self::assertSame(RateLimit::buildPrefixKey($ctx), $loadConfig['prefixKey']);
        self::assertSame('noIdentifier:/api/auth/local:203.0.113.1', $loadConfig['prefixKey']);
        self::assertSame(42, $loadConfig['max']);
        self::assertSame(['min' => 1], $loadConfig['interval']);
    }

    public function testBuildRateLimitLoadConfigAlwaysSetsHandlerLast(): void
    {
        $ctx = self::makeCtx('/api/auth/forgot-password', body: ['email' => 'a@b.com']);
        $evilHandler = static fn (): string => 'evil';

        $loadConfig = RateLimit::buildRateLimitLoadConfig($ctx, ['handler' => $evilHandler], []);

        self::assertNotSame($evilHandler, $loadConfig['handler']);
        $this->expectException(RateLimitError::class);
        ($loadConfig['handler'])();
    }
}
