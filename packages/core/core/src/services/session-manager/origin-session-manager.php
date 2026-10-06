<?php

declare(strict_types=1);

namespace Strapi\Core\Services\SessionManager;

use Strapi\Core\Services\SessionManager;

/**
 * The `OriginSessionManager` of services/session-manager.ts: every method bound to one origin.
 *
 * @phpstan-import-type SessionData from SessionManager
 */
final class OriginSessionManager
{
    public function __construct(private readonly SessionManager $sessionManager, private readonly string $origin)
    {
    }

    /**
     * @param array{type?: 'refresh'|'session', metadata?: array<string, mixed>}|null $options
     * @return array{token: string, sessionId: string, absoluteExpiresAt: string}
     */
    public function generateRefreshToken(string $userId, ?string $deviceId = null, ?array $options = null): array
    {
        return $this->sessionManager->generateRefreshToken($userId, $deviceId, $this->origin, $options);
    }

    /** @return array{token: string}|array{error: string} */
    public function generateAccessToken(string $refreshToken): array
    {
        return $this->sessionManager->generateAccessToken($refreshToken, $this->origin);
    }

    /** @return array{token: string, sessionId: string, absoluteExpiresAt: string, type: string}|array{error: string} */
    public function rotateRefreshToken(string $refreshToken): array
    {
        return $this->sessionManager->rotateRefreshToken($refreshToken, $this->origin);
    }

    /** @return array{isValid: bool, payload: array<string, mixed>|null} */
    public function validateAccessToken(string $token): array
    {
        return $this->sessionManager->validateAccessToken($token, $this->origin);
    }

    /** @return array{isValid: bool, userId?: string, sessionId?: string} */
    public function validateRefreshToken(string $token): array
    {
        return $this->sessionManager->validateRefreshToken($token, $this->origin);
    }

    public function invalidateRefreshToken(string $userId, ?string $deviceId = null): void
    {
        $this->sessionManager->invalidateRefreshToken($this->origin, $userId, $deviceId);
    }

    /** @return list<SessionData> */
    public function listSessions(string $userId): array
    {
        return $this->sessionManager->listSessions($this->origin, $userId);
    }

    public function revokeSessionById(string $userId, string $sessionId): bool
    {
        return $this->sessionManager->revokeSessionById($this->origin, $userId, $sessionId);
    }

    public function isSessionActive(string $sessionId): bool
    {
        return $this->sessionManager->isSessionActive($sessionId, $this->origin);
    }
}
