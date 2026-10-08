<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\Oauth2;
use Strapi\Plugin\UsersPermissions\Utils\TrimGrantSessionResponse;

/** Port of server/src/utils/__tests__/trim-grant-session-response.test.js. */
final class TrimGrantSessionResponseTest extends TestCase
{
    private const BROWSER_COOKIE_LIMIT = 4096;
    private const SESSION_COOKIE_NAME = 'koa.sess';
    private const SESSION_COOKIE_SECRET = 'trim-grant-session-response-test-secret';
    private const GRANT_PROVIDER_KEY = 'cognito-test-client';

    private static function toBase64Url(mixed $value): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
    }

    private static function makeJwt(int $payloadPadding = 0, string $audience = self::GRANT_PROVIDER_KEY): string
    {
        $header = self::toBase64Url(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test']);
        $payload = self::toBase64Url([
            'sub' => 'user-1',
            'aud' => $audience,
            'email' => 'user@' . str_repeat('x', $payloadPadding) . 'example.com',
        ]);
        $signature = self::toBase64Url(['sig' => 'test']);

        return "{$header}.{$payload}.{$signature}";
    }

    /** `cookie.serialize(name, signature.sign(base64(JSON), secret)).length` (cookie-signature) */
    private static function estimateSignedSessionCookieBytes(mixed $sessionPayload): int
    {
        $value = base64_encode((string) json_encode($sessionPayload, JSON_UNESCAPED_SLASHES));
        $signed = $value . '.' . rtrim(base64_encode(hash_hmac('sha256', $value, self::SESSION_COOKIE_SECRET, true)), '=');

        return strlen(self::SESSION_COOKIE_NAME . '=' . rawurlencode($signed));
    }

    /** @param array<string, mixed> $tokenExchange @return array<string, mixed> */
    private static function buildGrantOAuth2Response(array $tokenExchange): array
    {
        return Oauth2::tokensToQueryPayload(['oauth' => 2, 'key' => self::GRANT_PROVIDER_KEY], $tokenExchange);
    }

    public function testKeepsOnlyIdTokenForCognito(): void
    {
        $idToken = self::makeJwt(1200);
        $accessToken = self::makeJwt(1200);

        self::assertSame(['id_token' => $idToken], TrimGrantSessionResponse::trimGrantSessionResponse([
            'id_token' => $idToken,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-token',
            'raw' => [
                'id_token' => $idToken,
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ],
        ], 'cognito'));
    }

    public function testShrinksACognitoSizedGrantPayloadBelowTheBrowserCookieLimit(): void
    {
        $idToken = self::makeJwt(1800);
        $accessToken = self::makeJwt(1800);
        $grantResponse = [
            'id_token' => $idToken,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-token',
            'raw' => [
                'id_token' => $idToken,
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ],
        ];

        $before = strlen((string) json_encode($grantResponse));
        $trimmed = TrimGrantSessionResponse::trimGrantSessionResponse($grantResponse, 'cognito');
        $after = strlen((string) json_encode($trimmed));

        self::assertGreaterThan(self::BROWSER_COOKIE_LIMIT, $before);
        self::assertLessThan(self::BROWSER_COOKIE_LIMIT, $after);
        self::assertSame(['id_token' => $idToken], $trimmed);
    }

    public function testShrinksGrantShapedCognitoPayloadsBelowTheSignedSessionCookieLimit(): void
    {
        $idToken = self::makeJwt(1800);
        $accessToken = self::makeJwt(1800);
        $grantResponse = self::buildGrantOAuth2Response([
            'id_token' => $idToken,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);

        $sessionBeforeTrim = ['grant' => ['response' => $grantResponse]];
        $trimmed = TrimGrantSessionResponse::trimGrantSessionResponse($grantResponse, 'cognito');
        $sessionAfterTrim = ['grant' => ['response' => $trimmed]];

        self::assertSame([
            'id_token' => $idToken,
            'access_token' => $accessToken,
            'refresh_token' => 'refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], $grantResponse['raw']);
        self::assertGreaterThan(self::BROWSER_COOKIE_LIMIT, self::estimateSignedSessionCookieBytes($sessionBeforeTrim));
        self::assertLessThan(self::BROWSER_COOKIE_LIMIT, self::estimateSignedSessionCookieBytes($sessionAfterTrim));
        self::assertSame(['id_token' => $idToken], $trimmed);
    }

    public function testKeepsOnlyAccessTokenForBearerTokenProviders(): void
    {
        self::assertSame(['access_token' => 'google-access-token'], TrimGrantSessionResponse::trimGrantSessionResponse([
            'access_token' => 'google-access-token',
            'refresh_token' => 'refresh-token',
            'raw' => ['access_token' => 'google-access-token', 'scope' => 'email'],
        ], 'google'));
    }

    public function testKeepsTwitterOauth1FieldsAndScreenName(): void
    {
        self::assertSame([
            'access_token' => 'twitter-token',
            'access_secret' => 'twitter-secret',
            'raw' => ['screen_name' => 'strapi_user'],
        ], TrimGrantSessionResponse::trimGrantSessionResponse([
            'access_token' => 'twitter-token',
            'access_secret' => 'twitter-secret',
            'raw' => [
                'screen_name' => 'strapi_user',
                'user_id' => '12345',
                'oauth_token' => 'twitter-token',
                'oauth_token_secret' => 'twitter-secret',
            ],
        ], 'twitter'));
    }

    public function testKeepsVkEmailAndUserIdFromTheGrantSession(): void
    {
        self::assertSame([
            'access_token' => 'vk-token',
            'raw' => ['email' => 'user@example.com', 'user_id' => 42],
        ], TrimGrantSessionResponse::trimGrantSessionResponse([
            'access_token' => 'vk-token',
            'raw' => [
                'email' => 'user@example.com',
                'user_id' => 42,
                'expires_in' => 86400,
            ],
        ], 'vk'));
    }

    public function testReturnsTheOriginalValueWhenGrantResponseIsMissing(): void
    {
        self::assertNull(TrimGrantSessionResponse::trimGrantSessionResponse(null, 'google'));
    }
}
