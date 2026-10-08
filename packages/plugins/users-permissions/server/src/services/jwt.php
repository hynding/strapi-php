<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Admin\Services\Token;
use Strapi\Core\Strapi;
use Strapi\Core\Utils\Jwt as JwtLib;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/services/jwt.js.
 *
 * @description: A set of functions similar to controller's actions to avoid code duplication.
 */
final class Jwt
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, mixed>|null */
    public function getToken(Context $ctx): ?array
    {
        $authorization = $ctx->header('authorization');

        if ($authorization === null || $authorization === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $authorization) ?: [];

        if (strtolower($parts[0] ?? '') !== 'bearer' || count($parts) !== 2) {
            return null;
        }

        return $this->verify($parts[1]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $jwtOptions
     */
    public function issue(array $payload, array $jwtOptions = []): string
    {
        $mode = $this->strapi->config()->get('plugin::users-permissions.jwtManagement', 'legacy-support');

        if ($mode === 'refresh') {
            $userId = (string) ($payload['id'] ?? $payload['userId'] ?? '');
            if ($userId === '') {
                throw new \RuntimeException('Cannot issue token: missing user id');
            }

            $refresh = $this->strapi->sessionManager()('users-permissions')->generateRefreshToken($userId, null, ['type' => 'refresh']);

            $access = $this->strapi->sessionManager()('users-permissions')->generateAccessToken($refresh['token']);
            if (array_key_exists('error', $access)) {
                throw new \RuntimeException('Failed to generate access token');
            }

            return (string) $access['token'];
        }

        // _.defaults(jwtOptions, strapi.config.get('plugin::users-permissions.jwt'))
        $defaults = $this->strapi->config()->get('plugin::users-permissions.jwt');
        if (is_array($defaults)) {
            foreach ($defaults as $key => $value) {
                if (!array_key_exists($key, $jwtOptions) || $jwtOptions[$key] === null) {
                    $jwtOptions[$key] = $value;
                }
            }
        }

        $secret = $this->strapi->config()->get('plugin::users-permissions.jwtSecret');

        return self::sign($payload, is_string($secret) ? $secret : '', $jwtOptions);
    }

    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        $mode = $this->strapi->config()->get('plugin::users-permissions.jwtManagement', 'legacy-support');

        if ($mode === 'refresh') {
            // Accept only access tokens minted by the SessionManager for UP
            $result = $this->strapi->sessionManager()('users-permissions')->validateAccessToken($token);
            $payload = $result['payload'] ?? null;
            if (!($result['isValid'] ?? false) || !is_array($payload) || ($payload['type'] ?? null) !== 'access') {
                throw new \RuntimeException('Invalid token.');
            }

            $userId = $payload['userId'] ?? null;
            // `Number(userId) || userId`
            $id = is_numeric($userId) && (float) $userId != 0 ? $userId + 0 : $userId;

            $user = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['id' => $id]]);
            if ($user === null) {
                throw new \RuntimeException('Invalid token.');
            }

            // Surface the sessionId so the strategy can flag the "current" session.
            $verified = ['id' => $user['id']];
            if (isset($payload['sessionId'])) {
                $verified['sessionId'] = $payload['sessionId'];
            }

            return $verified;
        }

        $jwtConfig = $this->strapi->config()->get('plugin::users-permissions.jwt', []);
        $algorithm = is_array($jwtConfig) && is_string($jwtConfig['algorithm'] ?? null) && $jwtConfig['algorithm'] !== '' ? $jwtConfig['algorithm'] : 'HS256';
        $secret = $this->strapi->config()->get('plugin::users-permissions.jwtSecret');

        try {
            return JwtLib::decode($token, is_string($secret) ? $secret : '', $algorithm);
        } catch (\Throwable) {
            throw new \RuntimeException('Invalid token.');
        }
    }

    /**
     * `jsonwebtoken.sign(payload, secret, options)`: `iat`, `expiresIn` → `exp`, `notBefore` → `nbf`,
     * `issuer`/`audience`/`subject`/`jwtid` claims, `algorithm` (default HS256) and `keyid`.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public static function sign(array $payload, string $secret, array $options = []): string
    {
        $algorithm = is_string($options['algorithm'] ?? null) && $options['algorithm'] !== '' ? $options['algorithm'] : 'HS256';
        $now = time();

        $claims = $payload;
        if (!($options['noTimestamp'] ?? false)) {
            $claims['iat'] ??= $now;
        }
        $iat = is_int($claims['iat'] ?? null) ? $claims['iat'] : $now;

        if (isset($options['expiresIn'])) {
            $seconds = Token::expiresInToSeconds($options['expiresIn']);
            if ($seconds !== null) {
                $claims['exp'] = $iat + $seconds;
            }
        }
        if (isset($options['notBefore'])) {
            $seconds = Token::expiresInToSeconds($options['notBefore']);
            if ($seconds !== null) {
                $claims['nbf'] = $iat + $seconds;
            }
        }
        foreach (['issuer' => 'iss', 'audience' => 'aud', 'subject' => 'sub', 'jwtid' => 'jti'] as $option => $claim) {
            if (isset($options[$option])) {
                $claims[$claim] = $options[$option];
            }
        }

        return JwtLib::encode($claims, $secret, $algorithm);
    }
}
