<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use Strapi\Core\Tests\BootedAppTestCase;

/**
 * The claims of the tokens session-manager.ts signs. Upstream signs refresh tokens with
 * jsonwebtoken's `noTimestamp`, which drops the payload's `iat`, and access tokens without it.
 */
final class SessionManagerClaimsTest extends BootedAppTestCase
{
    /** @return array<string, mixed> */
    private static function claims(string $token): array
    {
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        self::assertIsArray($payload);

        return $payload;
    }

    public function testRefreshTokensCarryNoIat(): void
    {
        $sessions = self::strapi()->sessionManager()('users-permissions');
        $refresh = $sessions->generateRefreshToken('1', 'device-a')['token'];

        self::assertSame(['exp', 'sessionId', 'type', 'userId'], self::sortedKeys(self::claims($refresh)));

        $rotated = $sessions->rotateRefreshToken($refresh);
        self::assertArrayHasKey('token', $rotated);
        self::assertSame(['exp', 'sessionId', 'type', 'userId'], self::sortedKeys(self::claims($rotated['token'])));
    }

    public function testAccessTokensCarryIat(): void
    {
        $sessions = self::strapi()->sessionManager()('users-permissions');
        $refresh = $sessions->generateRefreshToken('2', 'device-b')['token'];
        $access = $sessions->generateAccessToken($refresh);

        self::assertArrayHasKey('token', $access);
        self::assertSame(['exp', 'iat', 'sessionId', 'type', 'userId'], self::sortedKeys(self::claims($access['token'])));
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private static function sortedKeys(array $claims): array
    {
        $keys = array_keys($claims);
        sort($keys);

        return $keys;
    }
}
