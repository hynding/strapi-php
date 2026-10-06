<?php

declare(strict_types=1);

namespace Strapi\Core\Services\SessionManager;

use Strapi\Database\Database;

/**
 * The `DatabaseSessionProvider` of services/session-manager.ts: sessions stored in a content type
 * (`admin::session`, registered by the admin package).
 *
 * @phpstan-import-type SessionData from \Strapi\Core\Services\SessionManager
 */
final class DatabaseSessionProvider implements SessionProvider
{
    public function __construct(private readonly Database $db, private readonly string $contentType)
    {
    }

    public function create(array $session): array
    {
        /** @var SessionData $result */
        $result = $this->db->query($this->contentType)->create(['data' => $session]);

        return $result;
    }

    public function findBySessionId(string $sessionId): ?array
    {
        /** @var SessionData|null $result */
        $result = $this->db->query($this->contentType)->findOne(['where' => ['sessionId' => $sessionId]]);

        return $result;
    }

    public function findByUser(array $criteria): array
    {
        /** @var list<SessionData> $results */
        $results = $this->db->query($this->contentType)->findMany([
            'where' => [
                'userId' => $criteria['userId'],
                'origin' => $criteria['origin'],
                ...(isset($criteria['status']) ? ['status' => $criteria['status']] : []),
            ],
            'orderBy' => ['createdAt' => 'DESC'],
        ]);

        return $results;
    }

    public function updateBySessionId(string $sessionId, array $data): void
    {
        $this->db->query($this->contentType)->update(['where' => ['sessionId' => $sessionId], 'data' => $data]);
    }

    public function deleteBySessionId(string $sessionId): void
    {
        $this->db->query($this->contentType)->delete(['where' => ['sessionId' => $sessionId]]);
    }

    public function deleteExpired(): void
    {
        $this->db->query($this->contentType)->deleteMany(['where' => ['absoluteExpiresAt' => ['$lt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z')]]]);
    }

    public function deleteBy(array $criteria): void
    {
        $this->db->query($this->contentType)->deleteMany(['where' => [
            ...(!empty($criteria['userId']) ? ['userId' => $criteria['userId']] : []),
            ...(!empty($criteria['origin']) ? ['origin' => $criteria['origin']] : []),
            ...(!empty($criteria['deviceId']) ? ['deviceId' => $criteria['deviceId']] : []),
        ]]);
    }
}
