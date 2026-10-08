<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Services;

require_once __DIR__ . '/../BootedApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Core\Utils\Jwt as JwtLib;
use Strapi\Plugin\UsersPermissions\Services\Jwt;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;

/**
 * Port of server/src/services/__tests__/jwt.test.js. Upstream mocks the session manager and the
 * database; here they are the booted app's (origin `users-permissions` defined by the plugin's
 * bootstrap).
 */
final class JwtTest extends TestCase
{
    private static ?Strapi $strapi = null;

    private mixed $previousMode = null;

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
        $this->previousMode = self::strapi()->config()->get('plugin::users-permissions.jwtManagement');
    }

    protected function tearDown(): void
    {
        self::strapi()->config()->set('plugin::users-permissions.jwtManagement', $this->previousMode);
        self::strapi()->config()->set('plugin::users-permissions.jwt', ['expiresIn' => '30d']);
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private static function service(): Jwt
    {
        return new Jwt(self::strapi());
    }

    private static function refreshMode(): void
    {
        self::strapi()->config()->set('plugin::users-permissions.jwtManagement', 'refresh');
    }

    /** @return array<string, mixed> */
    private static function decode(string $token): array
    {
        $payload = json_decode(JwtLib::base64UrlDecode(explode('.', $token)[1] ?? ''), true);

        return is_array($payload) ? $payload : [];
    }

    // issue method for refresh mode

    public function testIssueGeneratesARefreshSessionAndReturnsItsAccessToken(): void
    {
        self::refreshMode();
        $user = BootedApp::createUser(self::strapi());

        $token = self::service()->issue(['id' => $user['id']]);

        $payload = self::decode($token);
        self::assertSame('access', $payload['type'] ?? null);
        self::assertSame((string) $user['id'], $payload['userId'] ?? null);

        // the refresh session has no deviceId
        $sessions = self::strapi()->sessionManager()('users-permissions')->listSessions((string) $user['id']);
        self::assertCount(1, $sessions);
        self::assertNull($sessions[0]['deviceId'] ?? null);
        self::assertSame($payload['sessionId'] ?? null, $sessions[0]['sessionId'] ?? null);
    }

    public function testIssueHandlesTheUserIdFromPayloadUserId(): void
    {
        self::refreshMode();

        $token = self::service()->issue(['userId' => 'user-456']);

        self::assertSame('user-456', self::decode($token)['userId'] ?? null);
    }

    public function testIssueThrowsWhenNoUserIdIsProvided(): void
    {
        self::refreshMode();

        $this->expectExceptionMessage('Cannot issue token: missing user id');
        self::service()->issue([]);
    }

    // verify method for refresh mode

    public function testVerifyValidatesAnAccessTokenAndReturnsTheUser(): void
    {
        self::refreshMode();
        $user = BootedApp::createUser(self::strapi());
        $token = self::service()->issue(['id' => $user['id']]);

        $result = self::service()->verify($token);

        self::assertSame($user['id'], $result['id']);
        self::assertSame(self::decode($token)['sessionId'] ?? null, $result['sessionId'] ?? null);
    }

    public function testVerifyThrowsForAnInvalidToken(): void
    {
        self::refreshMode();

        $this->expectExceptionMessage('Invalid token.');
        self::service()->verify('invalid-token');
    }

    public function testVerifyThrowsForTheWrongTokenType(): void
    {
        self::refreshMode();
        $user = BootedApp::createUser(self::strapi());
        $refresh = self::strapi()->sessionManager()('users-permissions')->generateRefreshToken((string) $user['id'], null, ['type' => 'refresh']);

        $this->expectExceptionMessage('Invalid token.');
        self::service()->verify($refresh['token']);
    }

    public function testVerifyThrowsWhenTheUserIsNotFound(): void
    {
        self::refreshMode();

        $token = self::service()->issue(['id' => 987654]);

        $this->expectExceptionMessage('Invalid token.');
        self::service()->verify($token);
    }

    // verify method for legacy-support mode (issue #26587)

    private static function legacyMode(): string
    {
        self::strapi()->config()->set('plugin::users-permissions.jwtManagement', 'legacy-support');
        self::strapi()->config()->set('plugin::users-permissions.jwt', []);
        $secret = self::strapi()->config()->get('plugin::users-permissions.jwtSecret');

        return is_string($secret) ? $secret : '';
    }

    public function testLegacyAcceptsHs256Tokens(): void
    {
        $secret = self::legacyMode();

        $token = JwtLib::encode(['id' => 1, 'iat' => time()], $secret, 'HS256');

        self::assertSame(1, self::service()->verify($token)['id'] ?? null);
    }

    public function testLegacyRejectsHs384AndHs512TokensWhenNoAlgorithmIsConfigured(): void
    {
        $secret = self::legacyMode();

        foreach (['HS384', 'HS512'] as $i => $algorithm) {
            try {
                self::service()->verify(JwtLib::encode(['id' => 2 + $i], $secret, $algorithm));
                self::fail("{$algorithm} must be rejected");
            } catch (\RuntimeException $e) {
                self::assertSame('Invalid token.', $e->getMessage());
            }
        }
    }

    public function testLegacyRejectsUnexpectedAlgorithmsOnceAlgorithmIsConfigured(): void
    {
        $secret = self::legacyMode();
        self::strapi()->config()->set('plugin::users-permissions.jwt', ['algorithm' => 'HS256']);

        $this->expectExceptionMessage('Invalid token.');
        self::service()->verify(JwtLib::encode(['id' => 4], $secret, 'HS384'));
    }

    public function testLegacyIssueSignsWithTheConfiguredOptions(): void
    {
        $secret = self::legacyMode();
        self::strapi()->config()->set('plugin::users-permissions.jwt', ['expiresIn' => '30d', 'issuer' => 'up-issuer']);

        $token = self::service()->issue(['id' => 7]);

        $payload = JwtLib::decode($token, $secret, 'HS256');
        self::assertSame(7, $payload['id']);
        self::assertSame('up-issuer', $payload['iss']);
        self::assertSame(30 * 24 * 60 * 60, $payload['exp'] - $payload['iat']);
    }
}
