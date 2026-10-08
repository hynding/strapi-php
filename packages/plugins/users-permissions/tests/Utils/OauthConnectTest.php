<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Utils;

require_once __DIR__ . '/../BootedApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Middlewares\Session;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\Oauth1;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\Oauth2;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\OauthConnect;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\Providers;
use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;
use Strapi\Plugin\UsersPermissions\Utils\VerifyJwtWithJwks;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/utils/__tests__/oauth-connect.test.js. Upstream mocks `global.fetch` and the
 * plugin store; here the HTTP calls go to {@see ProviderHttp::$fetch} and the store is the booted
 * app's.
 */
final class OauthConnectTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $fetchCalls = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function tearDown(): void
    {
        ProviderHttp::$fetch = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    /** @param array<string, mixed> $grant */
    private static function setGrantStore(array $grant): void
    {
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'grant', 'value' => $grant]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $session koa-session `ctx.session` (null: no session middleware)
     * @param array<string, mixed> $state
     */
    private static function ctx(string $url, array $query = [], ?array $session = [], array $state = []): Context
    {
        $ctx = BootedApp::ctx('GET', 'http://localhost:1337' . $url, null, [], $query, [], $state);
        if ($session !== null) {
            $ctx->state()->set(Session::STATE_KEY, $session);
        }

        return $ctx;
    }

    /** @param array<string, mixed> $tokenResponse */
    private function mockTokenExchange(array $tokenResponse, int $status = 200): void
    {
        ProviderHttp::$fetch = function (string $url, array $options) use ($tokenResponse, $status): array {
            $this->fetchCalls[] = [$url, $options];

            return ['ok' => $status < 300, 'status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => (string) json_encode($tokenResponse)];
        };
    }

    public function testJwkToKeyObjectConvertsRsaJwkToVerifiableKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $jwk = [
            'kty' => 'RSA',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];

        $keyObject = VerifyJwtWithJwks::jwkToKeyObject($jwk);
        $converted = openssl_pkey_get_details($keyObject);

        self::assertIsArray($converted);
        self::assertSame(OPENSSL_KEYTYPE_RSA, $converted['type']);
        self::assertSame($details['key'], $converted['key']);
    }

    public function testBuildProviderConfigMergesStoredSettingsWithDefaults(): void
    {
        $config = OauthConnect::buildProviderConfig('google', [
            'key' => 'client-id',
            'secret' => 'client-secret',
            'scope' => ['email'],
            'callback' => 'http://localhost:3000/auth/google/callback',
        ], 'http://localhost:1337/api/connect/google/callback');

        self::assertNotNull($config);
        self::assertSame('google', $config['name']);
        self::assertSame(2, $config['oauth']);
        self::assertSame('client-id', $config['key']);
        self::assertSame('http://localhost:1337/api/connect/google/callback', $config['redirect_uri']);
    }

    public function testBuildProviderConfigFallsBackToStoreDefinedEndpointsForCustomProviders(): void
    {
        $config = OauthConnect::buildProviderConfig('my-custom-idp', [
            'enabled' => true,
            'oauth' => 2,
            'authorize_url' => 'https://idp.example.com/authorize',
            'access_url' => 'https://idp.example.com/token',
            'scope_delimiter' => ' ',
            'key' => 'custom-key',
            'secret' => 'custom-secret',
            'scope' => ['openid'],
            'callback' => 'http://localhost:3000/auth/custom/callback',
        ], 'http://localhost:1337/api/connect/my-custom-idp/callback');

        self::assertNotNull($config);
        self::assertSame('my-custom-idp', $config['name']);
        self::assertSame(2, $config['oauth']);
        self::assertSame('https://idp.example.com/authorize', $config['authorize_url']);
        self::assertSame('https://idp.example.com/token', $config['access_url']);
        self::assertSame('custom-key', $config['key']);
    }

    public function testBuildProviderConfigReturnsNullWhenCustomProviderLacksEndpoints(): void
    {
        self::assertNull(OauthConnect::buildProviderConfig(
            'broken-custom',
            ['enabled' => true, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost/cb'],
            'http://localhost:1337/api/connect/broken-custom/callback'
        ));
    }

    public function testRedirectWithPayloadSerializesTokenResponseForFrontendCallback(): void
    {
        $ctx = self::ctx('/api/connect/google/callback');
        OauthConnect::redirectWithPayload($ctx, 'http://localhost:3000/callback', [
            'access_token' => 'abc',
            'raw' => ['access_token' => 'abc', 'token_type' => 'bearer'],
        ]);

        $location = $ctx->responseHeader('Location') ?? '';
        self::assertStringContainsString('access_token=abc', $location);
        self::assertStringContainsString('raw%5Baccess_token%5D=abc', $location);
    }

    public function testOauth2BuildAuthorizeUrlSupportsSubdomainProviders(): void
    {
        $url = Oauth2::buildAuthorizeUrl([...Providers::PROVIDERS['cognito'], 'name' => 'cognito'], [
            'key' => 'id',
            'redirectUri' => 'http://localhost:1337/api/connect/cognito/callback',
            'scope' => ['openid', 'email'],
            'subdomain' => 'auth.example.com',
        ]);

        self::assertStringContainsString('https://auth.example.com/oauth2/authorize', $url);
        self::assertStringContainsString('client_id=id', $url);
    }

    public function testOauth1SignsQueryParamsViaParamsNotInTheBaseStringUri(): void
    {
        $withParams = Oauth1::buildOAuth1Header([
            'method' => 'GET',
            'url' => 'https://api.twitter.com/1.1/account/verify_credentials.json',
            'params' => ['include_email' => 'true'],
            'consumerKey' => 'ck',
            'clientCredential' => 'cs',
            'token' => 'tok',
            'tokenCredential' => 'toks',
        ]);

        $withQueryInUrl = Oauth1::buildOAuth1Header([
            'method' => 'GET',
            'url' => 'https://api.twitter.com/1.1/account/verify_credentials.json?include_email=true',
            'params' => [],
            'consumerKey' => 'ck',
            'clientCredential' => 'cs',
            'token' => 'tok',
            'tokenCredential' => 'toks',
        ]);

        self::assertStringContainsString('include_email', $withParams);
        self::assertStringNotContainsString('include_email', $withQueryInUrl);
    }

    public function testThrowsApplicationErrorWhenProviderIsDisabled(): void
    {
        self::setGrantStore(['google' => ['enabled' => false, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost/cb']]);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());

        $this->expectException(ApplicationError::class);
        $mw(self::ctx('/api/connect/google'), static fn (): null => null);
    }

    public function testRejectsOAuth2CallbackWhenSessionStateIsMissing(): void
    {
        self::setGrantStore(['google' => ['enabled' => true, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost:3000/cb', 'scope' => ['email']]]);
        $this->mockTokenExchange(['access_token' => 'tok']);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());
        $ctx = self::ctx('/api/connect/google/callback?code=abc&state=attacker', ['code' => 'abc', 'state' => 'attacker'], ['grant' => []]);

        $mw($ctx, static fn (): null => null);

        self::assertSame([], $this->fetchCalls);
        self::assertStringContainsString('error=oauth_error', $ctx->responseHeader('Location') ?? '');
    }

    public function testCallbackHandlerTokenExchangeFailuresRedirectWithOauthError(): void
    {
        self::setGrantStore(['google' => ['enabled' => true, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost:3000/cb', 'scope' => ['email']]]);
        $this->mockTokenExchange(['error_description' => 'boom'], 400);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());
        $ctx = self::ctx('/api/connect/google/callback?code=abc&state=good', ['code' => 'abc', 'state' => 'good'], ['grant' => ['state' => 'good']]);

        self::assertNull($mw($ctx, static fn (): null => null));
        $location = $ctx->responseHeader('Location') ?? '';
        self::assertStringContainsString('error=oauth_error', $location);
        self::assertStringContainsString('error_description=boom', $location);
    }

    public function testPreservesDynamicCallbackFromSessionOnCallbackLeg(): void
    {
        self::setGrantStore(['google' => ['enabled' => true, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost:3000/default-cb', 'scope' => ['email']]]);
        $this->mockTokenExchange(['access_token' => 'tok', 'token_type' => 'bearer']);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());
        $ctx = self::ctx('/api/connect/google/callback?code=abc&state=good', ['code' => 'abc', 'state' => 'good'], [
            'grant' => [
                'state' => 'good',
                'dynamic' => ['callback' => 'http://localhost:3000/custom-cb'],
            ],
        ]);

        $mw($ctx, static fn (): null => null);

        $location = $ctx->responseHeader('Location') ?? '';
        self::assertStringContainsString('http://localhost:3000/custom-cb', $location);
        self::assertStringNotContainsString('access_token', $location);
        self::assertSame(['response' => ['access_token' => 'tok']], OauthConnect::grant($ctx));
    }

    public function testKeepsOnlyTheProviderSpecificTokenFieldsInTheServerSession(): void
    {
        $idToken = 'header.' . str_repeat('x', 1800) . '.signature';
        self::setGrantStore(['cognito' => ['enabled' => true, 'key' => 'k', 'secret' => 's', 'subdomain' => 'auth.example.com', 'callback' => 'http://localhost:3000/cb', 'scope' => ['openid', 'email']]]);
        $this->mockTokenExchange([
            'id_token' => $idToken,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_type' => 'bearer',
        ]);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());
        $ctx = self::ctx('/api/connect/cognito/callback?code=abc&state=good', ['code' => 'abc', 'state' => 'good'], ['grant' => ['state' => 'good']]);

        $mw($ctx, static fn (): null => null);

        self::assertSame(['response' => ['id_token' => $idToken]], OauthConnect::grant($ctx));
        self::assertSame('http://localhost:3000/cb', $ctx->responseHeader('Location'));
        self::assertSame('https://auth.example.com/oauth2/token', $this->fetchCalls[0][0] ?? null);
    }

    public function testStartLegKeepsDynamicCallbackInSessionAcrossGrantRewrite(): void
    {
        self::setGrantStore(['google' => ['enabled' => true, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost:3000/default-cb', 'scope' => ['email']]]);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());
        $ctx = self::ctx(
            '/api/connect/google',
            [],
            ['grant' => ['dynamic' => ['callback' => 'http://localhost:3000/custom-cb']]],
            ['oauthConnect' => ['callback' => 'http://localhost:3000/custom-cb']],
        );

        $mw($ctx, static fn (): null => null);

        $grant = OauthConnect::grant($ctx) ?? [];
        self::assertSame(['callback' => 'http://localhost:3000/custom-cb'], $grant['dynamic'] ?? null);
        self::assertIsString($grant['state'] ?? null);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $ctx->responseHeader('Location') ?? '');
    }

    public function testRequiresTheSessionMiddleware(): void
    {
        self::setGrantStore(['google' => ['enabled' => true, 'key' => 'k', 'secret' => 's', 'callback' => 'http://localhost:3000/cb']]);

        $mw = OauthConnect::createOAuthConnectMiddleware(self::strapi());

        try {
            $mw(self::ctx('/api/connect/google', [], null), static fn (): null => null);
            self::fail('expected a 400');
        } catch (\Throwable $e) {
            self::assertSame('OAuth connect requires session middleware', $e->getMessage());
        }
    }

    public function testVerifyJwtWithJwksRejectsMalformedTokens(): void
    {
        $this->expectExceptionMessage('The provided token is not valid');
        VerifyJwtWithJwks::verifyJwtWithJwks('not-a-jwt', 'https://example.com/.well-known/jwks.json');
    }
}
