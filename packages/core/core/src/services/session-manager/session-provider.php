<?php

declare(strict_types=1);

namespace Strapi\Core\Services\SessionManager;

/**
 * The `SessionProvider` interface of services/session-manager.ts (split out: one class per file).
 *
 * @phpstan-import-type SessionData from \Strapi\Core\Services\SessionManager
 */
interface SessionProvider
{
    /** @param SessionData $session @return SessionData */
    public function create(array $session): array;

    /** @return SessionData|null */
    public function findBySessionId(string $sessionId): ?array;

    /** @param array{userId: string, origin: string, status?: string} $criteria @return list<SessionData> */
    public function findByUser(array $criteria): array;

    /** @param array<string, mixed> $data */
    public function updateBySessionId(string $sessionId, array $data): void;

    public function deleteBySessionId(string $sessionId): void;

    public function deleteExpired(): void;

    /** @param array{userId?: string|null, origin?: string|null, deviceId?: string|null} $criteria */
    public function deleteBy(array $criteria): void;
}
