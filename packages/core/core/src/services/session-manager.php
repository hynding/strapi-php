<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Utils\Jwt;
use Strapi\Core\Constants;
use Strapi\Core\Services\SessionManager\DatabaseSessionProvider;
use Strapi\Core\Services\SessionManager\OriginSessionManager;
use Strapi\Core\Services\SessionManager\SessionProvider;
use Strapi\Database\Database;

/**
 * Port of packages/core/core/src/services/session-manager.ts on firebase/php-jwt, with the same
 * claims (`userId`, `sessionId`, `type: 'refresh'|'access'`, `iat`, `exp`) and the same
 * rotation/absolute-expiry rules. `$sessionManager('admin')` (or `->origin('admin')`) returns the
 * origin-bound {@see OriginSessionManager}.
 *
 * @phpstan-type SessionData array{id?: mixed, userId: string, sessionId: string, deviceId?: string|null, origin: string, childId?: string|null, type?: 'refresh'|'session', status?: 'active'|'rotated'|'revoked', metadata?: array<string, mixed>|null, expiresAt: \DateTimeInterface|string, absoluteExpiresAt?: \DateTimeInterface|string|null, createdAt?: \DateTimeInterface|string|null, updatedAt?: \DateTimeInterface|string|null}
 * @phpstan-type SessionManagerConfig array{jwtSecret?: string, accessTokenLifespan: int, maxRefreshTokenLifespan: int, idleRefreshTokenLifespan: int, maxSessionLifespan: int, idleSessionLifespan: int, algorithm?: string, jwtOptions?: array<string, mixed>}
 */
final class SessionManager
{
    /** @var array<string, SessionManagerConfig> */
    private array $originConfigs = [];

    // Run expired cleanup only every N calls to avoid extra queries
    private int $cleanupInvocationCounter = 0;

    private readonly int $cleanupEveryCalls;

    public function __construct(private readonly SessionProvider $provider)
    {
        $this->cleanupEveryCalls = 50;
    }

    /** @param array{db: Database} $opts */
    public static function createSessionManager(array $opts): self
    {
        return new self(self::createDatabaseProvider($opts['db'], 'admin::session'));
    }

    public static function createDatabaseProvider(Database $db, string $contentType): SessionProvider
    {
        return new DatabaseSessionProvider($db, $contentType);
    }

    public function __invoke(string $origin): OriginSessionManager
    {
        return $this->origin($origin);
    }

    public function origin(string $origin): OriginSessionManager
    {
        self::assertOrigin($origin);

        return new OriginSessionManager($this, $origin);
    }

    private static function assertOrigin(string $origin): void
    {
        if ($origin === '') {
            throw new \RuntimeException('SessionManager: Origin parameter is required and must be a non-empty string');
        }
    }

    /** @param SessionManagerConfig $config */
    public function defineOrigin(string $origin, array $config): void
    {
        $this->originConfigs[$origin] = $config;
    }

    public function hasOrigin(string $origin): bool
    {
        return isset($this->originConfigs[$origin]);
    }

    /** @return SessionManagerConfig */
    private function getConfigForOrigin(string $origin): array
    {
        if (isset($this->originConfigs[$origin])) {
            return $this->originConfigs[$origin];
        }

        throw new \RuntimeException("SessionManager: Origin '{$origin}' is not defined. Please define it using defineOrigin('{$origin}', config).");
    }

    /** @param SessionManagerConfig $config */
    private function getJwtKey(array $config, string $algorithm, string $operation): string
    {
        $isAsymmetric = str_starts_with($algorithm, 'RS') || str_starts_with($algorithm, 'ES') || str_starts_with($algorithm, 'PS');

        if ($isAsymmetric) {
            if ($operation === 'sign') {
                $privateKey = $config['jwtOptions']['privateKey'] ?? null;
                if (is_string($privateKey) && $privateKey !== '') {
                    return $privateKey;
                }

                throw new \RuntimeException("SessionManager: Private key is required for asymmetric algorithm {$algorithm}. Please configure admin.auth.options.privateKey.");
            }
            $publicKey = $config['jwtOptions']['publicKey'] ?? null;
            if (is_string($publicKey) && $publicKey !== '') {
                return $publicKey;
            }

            throw new \RuntimeException("SessionManager: Public key is required for asymmetric algorithm {$algorithm}. Please configure admin.auth.options.publicKey.");
        }

        if (empty($config['jwtSecret'])) {
            throw new \RuntimeException("SessionManager: Secret key is required for symmetric algorithm {$algorithm}");
        }

        return $config['jwtSecret'];
    }

    public function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function maybeCleanupExpired(): void
    {
        $this->cleanupInvocationCounter += 1;
        if ($this->cleanupInvocationCounter >= $this->cleanupEveryCalls) {
            $this->cleanupInvocationCounter = 0;
            $this->provider->deleteExpired();
        }
    }

    public function cleanupThreshold(): int
    {
        return $this->cleanupEveryCalls;
    }

    /**
     * `jsonwebtoken` sign options that are not claims (issuer, audience, subject...) become claims.
     *
     * @param array<string, mixed> $jwtOptions
     * @return array<string, mixed>
     */
    private static function optionClaims(array $jwtOptions): array
    {
        $claims = [];
        $map = ['issuer' => 'iss', 'audience' => 'aud', 'subject' => 'sub', 'jwtid' => 'jti'];
        foreach ($map as $option => $claim) {
            if (isset($jwtOptions[$option])) {
                $claims[$claim] = $jwtOptions[$option];
            }
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $jwtOptions
     */
    private function sign(array $payload, string $key, string $algorithm, array $jwtOptions): string
    {
        unset($jwtOptions['expiresIn'], $jwtOptions['privateKey'], $jwtOptions['publicKey']);

        return Jwt::encode([...self::optionClaims($jwtOptions), ...$payload], $key, $algorithm);
    }

    /** @return array<string, mixed> */
    private function verify(string $token, string $key, string $algorithm): array
    {
        return Jwt::decode($token, $key, $algorithm);
    }

    private static function toTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value)) {
            $time = strtotime($value);

            return $time === false ? time() : $time;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 100_000_000_000 ? (int) floor($value / 1000) : (int) $value;
        }

        return time();
    }

    private static function iso(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array{type?: 'refresh'|'session', metadata?: array<string, mixed>}|null $options
     * @return array{token: string, sessionId: string, absoluteExpiresAt: string}
     */
    public function generateRefreshToken(string $userId, ?string $deviceId, string $origin, ?array $options = null): array
    {
        self::assertOrigin($origin);
        $this->maybeCleanupExpired();

        $config = $this->getConfigForOrigin($origin);
        $algorithm = $config['algorithm'] ?? Constants::DEFAULT_ALGORITHM;
        $jwtKey = $this->getJwtKey($config, $algorithm, 'sign');
        $sessionId = $this->generateSessionId();
        $tokenType = $options['type'] ?? 'refresh';
        $isRefresh = $tokenType === 'refresh';

        $idleLifespan = $isRefresh ? $config['idleRefreshTokenLifespan'] : $config['idleSessionLifespan'];
        $maxLifespan = $isRefresh ? $config['maxRefreshTokenLifespan'] : $config['maxSessionLifespan'];

        $now = time();
        $expiresAt = new \DateTimeImmutable('@' . ($now + $idleLifespan));
        $absoluteExpiresAt = new \DateTimeImmutable('@' . ($now + $maxLifespan));

        $session = [
            'userId' => $userId,
            'sessionId' => $sessionId,
            'origin' => $origin,
            'childId' => null,
            'type' => $tokenType,
            'status' => 'active',
            'expiresAt' => $expiresAt,
            'absoluteExpiresAt' => $absoluteExpiresAt,
        ];
        if ($deviceId !== null && $deviceId !== '') {
            $session['deviceId'] = $deviceId;
        }
        if (isset($options['metadata'])) {
            $session['metadata'] = $options['metadata'];
        }
        $record = $this->provider->create($session);

        $issuedAtSeconds = self::toTimestamp($record['createdAt'] ?? null);
        $expiresAtSeconds = self::toTimestamp($record['expiresAt']);

        $payload = ['userId' => $userId, 'sessionId' => $sessionId, 'type' => 'refresh', 'iat' => $issuedAtSeconds, 'exp' => $expiresAtSeconds];
        $token = $this->sign($payload, $jwtKey, $algorithm, $config['jwtOptions'] ?? []);

        return ['token' => $token, 'sessionId' => $sessionId, 'absoluteExpiresAt' => self::iso($absoluteExpiresAt->getTimestamp())];
    }

    /** @return array{isValid: bool, payload: array<string, mixed>|null} */
    public function validateAccessToken(string $token, string $origin): array
    {
        self::assertOrigin($origin);

        try {
            $config = $this->getConfigForOrigin($origin);
            $algorithm = $config['algorithm'] ?? Constants::DEFAULT_ALGORITHM;
            $payload = $this->verify($token, $this->getJwtKey($config, $algorithm, 'verify'), $algorithm);

            if (($payload['type'] ?? null) !== 'access') {
                return ['isValid' => false, 'payload' => null];
            }

            return ['isValid' => true, 'payload' => $payload];
        } catch (\Throwable) {
            return ['isValid' => false, 'payload' => null];
        }
    }

    /** @return array{isValid: bool, userId?: string, sessionId?: string} */
    public function validateRefreshToken(string $token, string $origin): array
    {
        self::assertOrigin($origin);

        try {
            $config = $this->getConfigForOrigin($origin);
            $algorithm = $config['algorithm'] ?? Constants::DEFAULT_ALGORITHM;
            $payload = $this->verify($token, $this->getJwtKey($config, $algorithm, 'verify'), $algorithm);
        } catch (\UnexpectedValueException | \DomainException) {
            return ['isValid' => false];
        }

        if (($payload['type'] ?? null) !== 'refresh') {
            return ['isValid' => false];
        }

        $session = $this->provider->findBySessionId((string) $payload['sessionId']);
        if ($session === null) {
            return ['isValid' => false];
        }

        $now = time();
        if (self::toTimestamp($session['expiresAt']) <= $now) {
            return ['isValid' => false];
        }

        if (!empty($session['absoluteExpiresAt']) && self::toTimestamp($session['absoluteExpiresAt']) <= $now) {
            return ['isValid' => false];
        }

        if (($session['status'] ?? null) !== 'active') {
            return ['isValid' => false];
        }

        if ((string) $session['userId'] !== (string) $payload['userId']) {
            return ['isValid' => false];
        }

        return ['isValid' => true, 'userId' => (string) $payload['userId'], 'sessionId' => (string) $payload['sessionId']];
    }

    public function invalidateRefreshToken(string $origin, string $userId, ?string $deviceId = null): void
    {
        $this->provider->deleteBy(['userId' => $userId, 'origin' => $origin, 'deviceId' => $deviceId]);
    }

    /** @return list<SessionData> */
    public function listSessions(string $origin, string $userId): array
    {
        self::assertOrigin($origin);

        return $this->provider->findByUser(['userId' => $userId, 'origin' => $origin, 'status' => 'active']);
    }

    public function revokeSessionById(string $origin, string $userId, string $sessionId): bool
    {
        self::assertOrigin($origin);

        $session = $this->provider->findBySessionId($sessionId);

        if ($session === null || (string) $session['userId'] !== $userId || $session['origin'] !== $origin) {
            return false;
        }

        $this->provider->deleteBySessionId($sessionId);

        return true;
    }

    /** @return array{token: string}|array{error: string} */
    public function generateAccessToken(string $refreshToken, string $origin): array
    {
        self::assertOrigin($origin);

        $validation = $this->validateRefreshToken($refreshToken, $origin);

        if (!$validation['isValid']) {
            return ['error' => 'invalid_refresh_token'];
        }

        $config = $this->getConfigForOrigin($origin);
        $algorithm = $config['algorithm'] ?? Constants::DEFAULT_ALGORITHM;
        $jwtKey = $this->getJwtKey($config, $algorithm, 'sign');
        $now = time();

        $payload = [
            'userId' => (string) ($validation['userId'] ?? ''),
            'sessionId' => (string) ($validation['sessionId'] ?? ''),
            'type' => 'access',
            'iat' => $now,
            'exp' => $now + (int) $config['accessTokenLifespan'],
        ];

        return ['token' => $this->sign($payload, $jwtKey, $algorithm, $config['jwtOptions'] ?? [])];
    }

    /** @return array{token: string, sessionId: string, absoluteExpiresAt: string, type: string}|array{error: string} */
    public function rotateRefreshToken(string $refreshToken, string $origin): array
    {
        self::assertOrigin($origin);

        try {
            $config = $this->getConfigForOrigin($origin);
            $algorithm = $config['algorithm'] ?? Constants::DEFAULT_ALGORITHM;
            $verifyKey = $this->getJwtKey($config, $algorithm, 'verify');
            $signKey = $this->getJwtKey($config, $algorithm, 'sign');
            $jwtOptions = $config['jwtOptions'] ?? [];

            $payload = $this->verify($refreshToken, $verifyKey, $algorithm);

            if (($payload['type'] ?? null) !== 'refresh') {
                return ['error' => 'invalid_refresh_token'];
            }

            $current = $this->provider->findBySessionId((string) $payload['sessionId']);
            if ($current === null || ($current['status'] ?? null) !== 'active') {
                return ['error' => 'invalid_refresh_token'];
            }

            // If parent already has a child, return the same child token
            if (!empty($current['childId'])) {
                $child = $this->provider->findBySessionId((string) $current['childId']);

                if ($child !== null) {
                    $childPayload = [
                        'userId' => (string) $child['userId'],
                        'sessionId' => $child['sessionId'],
                        'type' => 'refresh',
                        'iat' => self::toTimestamp($child['createdAt'] ?? null),
                        'exp' => self::toTimestamp($child['expiresAt']),
                    ];

                    return [
                        'token' => $this->sign($childPayload, $signKey, $algorithm, $jwtOptions),
                        'sessionId' => (string) $child['sessionId'],
                        'absoluteExpiresAt' => !empty($child['absoluteExpiresAt']) ? self::iso(self::toTimestamp($child['absoluteExpiresAt'])) : self::iso(0),
                        'type' => $child['type'] ?? 'refresh',
                    ];
                }
            }

            $now = time();
            $tokenType = $current['type'] ?? 'refresh';
            $idleLifespan = $tokenType === 'refresh' ? $config['idleRefreshTokenLifespan'] : $config['idleSessionLifespan'];

            // Enforce idle window since creation of the current token
            if (!empty($current['createdAt']) && $now - self::toTimestamp($current['createdAt']) > $idleLifespan) {
                return ['error' => 'idle_window_elapsed'];
            }

            // Enforce max family window using absoluteExpiresAt
            $absolute = !empty($current['absoluteExpiresAt']) ? self::toTimestamp($current['absoluteExpiresAt']) : $now;
            if ($absolute <= $now) {
                return ['error' => 'max_window_elapsed'];
            }

            // Create child token
            $childSessionId = $this->generateSessionId();
            $childExpiresAt = new \DateTimeImmutable('@' . ($now + $idleLifespan));

            $childSession = [
                'userId' => (string) $current['userId'],
                'sessionId' => $childSessionId,
                'origin' => $current['origin'],
                'childId' => null,
                'type' => $tokenType,
                'status' => 'active',
                'expiresAt' => $childExpiresAt,
                'absoluteExpiresAt' => !empty($current['absoluteExpiresAt']) ? $current['absoluteExpiresAt'] : new \DateTimeImmutable('@' . $absolute),
            ];
            if (!empty($current['deviceId'])) {
                $childSession['deviceId'] = $current['deviceId'];
            }
            if (!empty($current['metadata'])) {
                $childSession['metadata'] = $current['metadata'];
            }
            $childRecord = $this->provider->create($childSession);

            $payloadOut = [
                'userId' => (string) $current['userId'],
                'sessionId' => $childSessionId,
                'type' => 'refresh',
                'iat' => self::toTimestamp($childRecord['createdAt'] ?? null),
                'exp' => self::toTimestamp($childRecord['expiresAt']),
            ];

            $childToken = $this->sign($payloadOut, $signKey, $algorithm, $jwtOptions);

            $this->provider->updateBySessionId((string) $current['sessionId'], ['status' => 'rotated', 'childId' => $childSessionId]);

            return [
                'token' => $childToken,
                'sessionId' => $childSessionId,
                'absoluteExpiresAt' => !empty($childRecord['absoluteExpiresAt']) ? self::iso(self::toTimestamp($childRecord['absoluteExpiresAt'])) : self::iso($absolute),
                'type' => $tokenType,
            ];
        } catch (\Throwable) {
            return ['error' => 'invalid_refresh_token'];
        }
    }

    /**
     * Returns true when a session exists and is not expired. An expired session is deleted eagerly.
     */
    public function isSessionActive(string $sessionId, string $origin): bool
    {
        $session = $this->provider->findBySessionId($sessionId);
        if ($session === null || $session['origin'] !== $origin) {
            return false;
        }

        if (self::toTimestamp($session['expiresAt']) <= time()) {
            $this->provider->deleteBySessionId($sessionId);

            return false;
        }

        return true;
    }
}
