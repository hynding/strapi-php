<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Services;

require_once __DIR__ . '/../BootedApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Core\Utils\Jwt;
use Strapi\Plugin\UsersPermissions\Services\ProvidersRegistry;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;
use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;

/**
 * Port of server/src/services/__tests__/providers-registry.test.js. Upstream mocks
 * `bearerGet`/`fetchJson`/`twitterGet`/`verifyJwtWithJwks`; here every provider HTTP call goes
 * through {@see ProviderHttp::$fetch}, answered from a queue of JSON bodies (the last one repeats,
 * like `mockResolvedValue`), and Cognito's id_token is a real RS256 token checked against a JWKS
 * served the same way.
 */
final class ProvidersRegistryTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<mixed> */
    private array $responses = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $requests = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        $this->responses = [];
        $this->requests = [];
        ProviderHttp::$fetch = function (string $url, array $options): array {
            $this->requests[] = [$url, $options];
            $body = count($this->responses) > 1 ? array_shift($this->responses) : ($this->responses[0] ?? null);

            return ['ok' => true, 'status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => (string) json_encode($body)];
        };
    }

    protected function tearDown(): void
    {
        ProviderHttp::$fetch = null;
    }

    /** @param mixed ...$bodies */
    private function mockResponses(mixed ...$bodies): void
    {
        $this->responses = array_values($bodies);
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private static function authCallback(string $provider, array $args): array
    {
        $registry = new ProvidersRegistry(self::$strapi ?? throw new \LogicException('not booted'));
        $authCallback = $registry->get($provider)['authCallback'] ?? null;
        self::assertIsCallable($authCallback);

        return $authCallback($args);
    }

    private function assertAuthCallbackThrows(string $message, string $provider, array $args): void
    {
        try {
            self::authCallback($provider, $args);
            self::fail("expected '{$message}'");
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    // cognito authCallback — email_verified guard

    /**
     * A JWKS (served through the fetch mock) and an RS256 id_token carrying `$payload`.
     *
     * @param array<string, mixed> $payload
     */
    private function mockCognitoToken(array $payload): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $this->mockResponses(['keys' => [['kty' => 'RSA', 'kid' => 'kid-1', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]]);

        $header = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-1']));
        $body = $b64((string) json_encode($payload));
        openssl_sign("{$header}.{$body}", $signature, $privatePem, OPENSSL_ALGO_SHA256);

        return "{$header}.{$body}." . Jwt::base64UrlEncode($signature);
    }

    private const COGNITO_PROVIDERS = ['cognito' => ['jwksurl' => 'https://cognito.example.com/.well-known/jwks.json']];

    public function testCognitoReturnsUsernameAndEmailWhenEmailVerifiedIsTrue(): void
    {
        $idToken = $this->mockCognitoToken(['cognito:username' => 'john_doe', 'email' => 'john@example.com', 'email_verified' => true]);

        self::assertSame(['username' => 'john_doe', 'email' => 'john@example.com'], self::authCallback('cognito', [
            'grantResponse' => ['id_token' => $idToken],
            'providers' => self::COGNITO_PROVIDERS,
        ]));
        self::assertSame('https://cognito.example.com/.well-known/jwks.json', $this->requests[0][0] ?? null);
    }

    public function testCognitoThrowsWhenEmailVerifiedIsFalse(): void
    {
        $idToken = $this->mockCognitoToken(['cognito:username' => 'attacker', 'email' => 'victim@example.com', 'email_verified' => false]);

        $this->assertAuthCallbackThrows('Email not verified by Cognito', 'cognito', ['grantResponse' => ['id_token' => $idToken], 'providers' => self::COGNITO_PROVIDERS]);
    }

    public function testCognitoThrowsWhenEmailVerifiedIsAbsent(): void
    {
        $idToken = $this->mockCognitoToken(['cognito:username' => 'someone', 'email' => 'someone@example.com']);

        $this->assertAuthCallbackThrows('Email not verified by Cognito', 'cognito', ['grantResponse' => ['id_token' => $idToken], 'providers' => self::COGNITO_PROVIDERS]);
    }

    public function testCognitoThrowsWhenEmailVerifiedIsTheStringTrue(): void
    {
        // The OIDC spec requires email_verified to be a boolean; string "true" must NOT bypass the guard
        $idToken = $this->mockCognitoToken(['cognito:username' => 'tricky', 'email' => 'tricky@example.com', 'email_verified' => 'true']);

        $this->assertAuthCallbackThrows('Email not verified by Cognito', 'cognito', ['grantResponse' => ['id_token' => $idToken], 'providers' => self::COGNITO_PROVIDERS]);
    }

    public function testCognitoRejectsWhenIdTokenIsMissingFromTheOAuthSession(): void
    {
        $this->assertAuthCallbackThrows('Cognito authentication requires a completed OAuth session', 'cognito', ['providers' => self::COGNITO_PROVIDERS]);
    }

    public function testCognitoRejectsForgedQueryIdTokenWithoutAGrantSession(): void
    {
        $idToken = $this->mockCognitoToken(['cognito:username' => 'attacker', 'email' => 'victim@example.com', 'email_verified' => true]);

        $this->assertAuthCallbackThrows('Cognito authentication requires a completed OAuth session', 'cognito', ['query' => ['id_token' => $idToken], 'providers' => self::COGNITO_PROVIDERS]);
    }

    // google authCallback — verified email guard

    public function testGoogleAcceptsTheLegacyBooleanVerifiedEmail(): void
    {
        $this->mockResponses(['email' => 'john@example.com', 'verified_email' => true]);

        self::assertSame(['username' => 'john', 'email' => 'john@example.com'], self::authCallback('google', ['accessToken' => 'fake-token']));
        self::assertSame('https://oauth2.googleapis.com/tokeninfo?access_token=fake-token', $this->requests[0][0] ?? null);
    }

    public function testGoogleAcceptsTheOidcStringEmailVerified(): void
    {
        $this->mockResponses(['email' => 'jane@example.com', 'email_verified' => 'true']);

        self::assertSame(['username' => 'jane', 'email' => 'jane@example.com'], self::authCallback('google', ['accessToken' => 'fake-token']));
    }

    public function testGoogleThrowsWhenNotVerified(): void
    {
        foreach ([['verified_email' => false], ['email_verified' => 'false'], []] as $flags) {
            $this->mockResponses(['email' => 'victim@example.com', ...$flags]);
            $this->assertAuthCallbackThrows('Email not verified by Google', 'google', ['accessToken' => 'fake-token']);
        }
    }

    // discord authCallback — verified email guard

    public function testDiscordReturnsUsernameAndEmailWhenVerified(): void
    {
        $this->mockResponses(['username' => 'nelly', 'discriminator' => '0', 'email' => 'nelly@example.com', 'verified' => true]);

        self::assertSame(['username' => 'nelly', 'email' => 'nelly@example.com'], self::authCallback('discord', ['accessToken' => 'fake-token']));
        self::assertSame('Bearer fake-token', $this->requests[0][1]['headers']['Authorization'] ?? null);
    }

    public function testDiscordThrowsWhenNotVerified(): void
    {
        foreach ([['verified' => false], []] as $flags) {
            $this->mockResponses(['username' => 'attacker', 'email' => 'victim@example.com', ...$flags]);
            $this->assertAuthCallbackThrows('Email not verified by Discord', 'discord', ['accessToken' => 'fake-token']);
        }
    }

    // github authCallback — verified email guard

    public function testGithubReturnsThePrimaryEmailWhenItIsVerified(): void
    {
        $this->mockResponses(['login' => 'octocat', 'email' => null], [
            ['email' => 'secondary@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'octocat@example.com', 'primary' => true, 'verified' => true],
        ]);

        self::assertSame(['username' => 'octocat', 'email' => 'octocat@example.com'], self::authCallback('github', ['accessToken' => 'fake-token']));
    }

    public function testGithubThrowsWhenThePrimaryEmailIsUnverified(): void
    {
        $this->mockResponses(['login' => 'attacker', 'email' => null], [['email' => 'victim@example.com', 'primary' => true, 'verified' => false]]);

        $this->assertAuthCallbackThrows('Email not verified by GitHub', 'github', ['accessToken' => 'fake-token']);
    }

    public function testGithubThrowsWhenNoPrimaryEmailIsPresent(): void
    {
        $this->mockResponses(['login' => 'attacker', 'email' => null], [['email' => 'secondary@example.com', 'primary' => false, 'verified' => true]]);

        $this->assertAuthCallbackThrows('Email not verified by GitHub', 'github', ['accessToken' => 'fake-token']);
    }

    public function testGithubAcceptsAPublicProfileEmailOnlyWhenItIsVerified(): void
    {
        $this->mockResponses(['login' => 'octocat', 'email' => 'octocat@example.com'], [['email' => 'octocat@example.com', 'primary' => true, 'verified' => true]]);
        self::assertSame(['username' => 'octocat', 'email' => 'octocat@example.com'], self::authCallback('github', ['accessToken' => 'fake-token']));

        $this->mockResponses(['login' => 'attacker', 'email' => 'victim@example.com'], [['email' => 'victim@example.com', 'primary' => true, 'verified' => false]]);
        $this->assertAuthCallbackThrows('Email not verified by GitHub', 'github', ['accessToken' => 'fake-token']);
    }

    // auth0 authCallback — verified email guard

    private const AUTH0_PROVIDERS = ['auth0' => ['subdomain' => 'my-tenant.eu']];

    public function testAuth0ReturnsUsernameAndEmailWhenEmailVerified(): void
    {
        $this->mockResponses(['nickname' => 'john', 'email' => 'john@example.com', 'email_verified' => true]);

        self::assertSame(['username' => 'john', 'email' => 'john@example.com'], self::authCallback('auth0', ['accessToken' => 'fake-token', 'providers' => self::AUTH0_PROVIDERS]));
        self::assertSame('https://my-tenant.eu.auth0.com/userinfo', $this->requests[0][0] ?? null);
    }

    public function testAuth0ThrowsWhenARealEmailIsNotVerified(): void
    {
        $this->mockResponses(['nickname' => 'attacker', 'email' => 'victim@example.com', 'email_verified' => false]);

        $this->assertAuthCallbackThrows('Email not verified by Auth0', 'auth0', ['accessToken' => 'fake-token', 'providers' => self::AUTH0_PROVIDERS]);
    }

    public function testAuth0KeepsTheGeneratedFallbackEmailWhenNoEmailIsReturned(): void
    {
        $this->mockResponses(['nickname' => 'no mail user']);

        self::assertSame(['username' => 'no mail user', 'email' => 'no.mail.user@strapi.io'], self::authCallback('auth0', ['accessToken' => 'fake-token', 'providers' => self::AUTH0_PROVIDERS]));
    }

    // keycloak authCallback — verified email guard

    public function testKeycloak(): void
    {
        $providers = ['keycloak' => ['subdomain' => 'kc.example.com/realms/r']];
        $this->mockResponses(['preferred_username' => 'john', 'email' => 'john@example.com', 'email_verified' => true]);
        self::assertSame(['username' => 'john', 'email' => 'john@example.com'], self::authCallback('keycloak', ['accessToken' => 'fake-token', 'providers' => $providers]));
        self::assertSame('https://kc.example.com/realms/r/protocol/openid-connect/userinfo', $this->requests[0][0] ?? null);

        $this->mockResponses(['preferred_username' => 'attacker', 'email' => 'victim@example.com', 'email_verified' => false]);
        $this->assertAuthCallbackThrows('Email not verified by Keycloak', 'keycloak', ['accessToken' => 'fake-token', 'providers' => $providers]);
    }

    // patreon authCallback — verified email guard

    public function testPatreon(): void
    {
        $this->mockResponses(['data' => ['attributes' => ['full_name' => 'John Doe', 'email' => 'john@example.com', 'is_email_verified' => true]]]);
        self::assertSame(['username' => 'John Doe', 'email' => 'john@example.com'], self::authCallback('patreon', ['accessToken' => 'fake-token']));

        $this->mockResponses(['data' => ['attributes' => ['full_name' => 'Attacker', 'email' => 'victim@example.com', 'is_email_verified' => false]]]);
        $this->assertAuthCallbackThrows('Email not verified by Patreon', 'patreon', ['accessToken' => 'fake-token']);

        $this->mockResponses(['data' => ['attributes' => ['full_name' => 'Attacker', 'email' => 'victim@example.com']]]);
        $this->assertAuthCallbackThrows('Email not verified by Patreon', 'patreon', ['accessToken' => 'fake-token']);
    }

    // facebook authCallback — verified email guard

    public function testFacebook(): void
    {
        $this->mockResponses(['name' => 'John Doe', 'email' => 'john@example.com']);
        self::assertSame(['username' => 'John Doe', 'email' => 'john@example.com'], self::authCallback('facebook', ['accessToken' => 'fake-token']));
        self::assertSame('https://graph.facebook.com/me?fields=name%2Cemail', $this->requests[0][0] ?? null);

        $this->mockResponses(['name' => 'No Email User']);
        $this->assertAuthCallbackThrows('Email not verified by Facebook', 'facebook', ['accessToken' => 'fake-token']);
    }

    // twitter authCallback — server-side OAuth session guard

    private const TWITTER_PROVIDERS = ['twitter' => ['key' => 'key', 'secret' => 'secret']];
    private const TWITTER_GRANT = ['access_secret' => 'real-secret', 'raw' => ['screen_name' => 'real_user']];

    public function testTwitterReturnsUsernameAndEmailFromTheGrantSession(): void
    {
        $this->mockResponses(['screen_name' => 'real_user', 'email' => 'real_user@example.com']);

        self::assertSame(['username' => 'real_user', 'email' => 'real_user@example.com'], self::authCallback('twitter', [
            'accessToken' => 'fake-token',
            'grantResponse' => self::TWITTER_GRANT,
            'providers' => self::TWITTER_PROVIDERS,
        ]));
        self::assertStringStartsWith('OAuth ', $this->requests[0][1]['headers']['Authorization'] ?? '');
    }

    public function testTwitterRejectsForgedQueryParamsWithoutAGrantSession(): void
    {
        $this->assertAuthCallbackThrows('Twitter authentication requires a completed OAuth session', 'twitter', [
            'accessToken' => 'fake-token',
            'query' => ['access_secret' => 'forged-secret', 'raw[screen_name]' => 'victim'],
            'providers' => self::TWITTER_PROVIDERS,
        ]);
    }

    public function testTwitterThrowsWhenTwitterDoesNotReturnAnEmail(): void
    {
        $this->mockResponses(['screen_name' => 'real_user']);

        $this->assertAuthCallbackThrows('Email not verified by Twitter', 'twitter', [
            'accessToken' => 'fake-token',
            'grantResponse' => self::TWITTER_GRANT,
            'providers' => self::TWITTER_PROVIDERS,
        ]);
    }

    // twitch / linkedin / cas

    public function testTwitch(): void
    {
        $providers = ['twitch' => ['key' => 'client-id']];
        $this->mockResponses(['data' => [['login' => 'streamer', 'email' => 'streamer@example.com']]]);
        self::assertSame(['username' => 'streamer', 'email' => 'streamer@example.com'], self::authCallback('twitch', ['accessToken' => 'fake-token', 'providers' => $providers]));
        self::assertSame('client-id', $this->requests[0][1]['headers']['Client-Id'] ?? null);

        $this->mockResponses(['data' => [['login' => 'streamer']]]);
        $this->assertAuthCallbackThrows('Email not verified by Twitch', 'twitch', ['accessToken' => 'fake-token', 'providers' => $providers]);
    }

    public function testLinkedin(): void
    {
        $this->mockResponses(['localizedFirstName' => 'John'], ['elements' => [['handle~' => ['emailAddress' => 'john@example.com']]]]);
        self::assertSame(['username' => 'John', 'email' => 'john@example.com'], self::authCallback('linkedin', ['accessToken' => 'fake-token']));

        $this->mockResponses(['localizedFirstName' => 'John'], ['elements' => []]);
        $this->assertAuthCallbackThrows('Email not verified by LinkedIn', 'linkedin', ['accessToken' => 'fake-token']);
    }

    public function testCas(): void
    {
        $providers = ['cas' => ['subdomain' => 'cas.example.com/cas']];
        $this->mockResponses(['sub' => 'user-1', 'email' => 'john@example.com', 'email_verified' => true]);
        self::assertSame(['username' => 'user-1', 'email' => 'john@example.com'], self::authCallback('cas', ['accessToken' => 'fake-token', 'providers' => $providers]));

        $this->mockResponses(['sub' => 'attacker', 'email' => 'victim@example.com', 'email_verified' => false]);
        $this->assertAuthCallbackThrows('Email not verified by CAS', 'cas', ['accessToken' => 'fake-token', 'providers' => $providers]);
    }

    // vk authCallback — server-side OAuth session guard

    private const VK_GRANT = ['raw' => ['email' => 'john@example.com', 'user_id' => '12345']];

    public function testVkReturnsUsernameAndEmailFromTheGrantSessionTokenResponse(): void
    {
        $this->mockResponses(['response' => [['first_name' => 'John', 'last_name' => 'Doe']]]);

        self::assertSame(['username' => 'Doe John', 'email' => 'john@example.com'], self::authCallback('vk', ['accessToken' => 'real-vk-token', 'grantResponse' => self::VK_GRANT]));
    }

    public function testVkRejectsForgedQueryEmailWithoutAGrantSession(): void
    {
        $this->assertAuthCallbackThrows('VK authentication requires a completed OAuth session', 'vk', [
            'accessToken' => 'fake-token',
            'query' => ['raw' => ['email' => 'victim@example.com', 'user_id' => '99999']],
        ]);
    }

    public function testVkRejectsWhenTheGrantSessionHasNoEmail(): void
    {
        $this->assertAuthCallbackThrows('VK authentication requires a completed OAuth session', 'vk', [
            'accessToken' => 'fake-token',
            'grantResponse' => ['raw' => ['user_id' => '12345']],
        ]);
    }

    public function testVkRejectsWhenTheAccessTokenDoesNotResolveToAVkUser(): void
    {
        $this->mockResponses(['response' => []]);

        $this->assertAuthCallbackThrows('Invalid VK access token', 'vk', ['accessToken' => 'invalid-token', 'grantResponse' => self::VK_GRANT]);
    }

    public function testRunRejectsUnknownProviders(): void
    {
        $registry = new ProvidersRegistry(self::$strapi ?? throw new \LogicException('not booted'));

        $this->expectExceptionMessage('Unknown auth provider');
        $registry->run(['provider' => 'nope']);
    }
}
