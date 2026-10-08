<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityService;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/entity-service/index.ts: the deprecated v4 Entity
 * Service, a thin wrapper over the Document Service (`strapi.entityService`).
 *
 * @deprecated use `strapi.documents(uid)` instead
 */
final class EntityService
{
    private bool $warned = false;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createEntityService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private function warn(): void
    {
        if (!$this->warned) {
            $this->warned = true;
            $this->strapi->log()->warning('`strapi.entityService` is deprecated and will be removed in the next major version. Use `strapi.documents` instead.');
        }
    }

    /** @param array<string, mixed> $params */
    public function findMany(string $uid, array $params = []): mixed
    {
        $this->warn();
        if ($this->strapi->contentType($uid)->isSingleType()) {
            return $this->strapi->documents($uid)->findFirst($params);
        }

        return $this->strapi->documents($uid)->findMany($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function findOne(string $uid, int|string $id, array $params = []): ?array
    {
        $this->warn();
        $entry = $this->strapi->db()->query($uid)->findOne(['where' => ['id' => $id], 'select' => ['documentId', 'locale', 'publishedAt']]);
        if ($entry === null) {
            return null;
        }

        return $this->strapi->documents($uid)->findOne([...$params, 'documentId' => $entry['documentId'], 'locale' => $entry['locale'] ?? null, 'status' => $entry['publishedAt'] !== null ? 'published' : 'draft']);
    }

    /** @param array<string, mixed> $params */
    public function count(string $uid, array $params = []): int
    {
        $this->warn();

        return $this->strapi->documents($uid)->count($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(string $uid, array $params = []): array
    {
        $this->warn();

        return $this->strapi->documents($uid)->create($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function update(string $uid, int|string $id, array $params = []): ?array
    {
        $this->warn();
        $entry = $this->strapi->db()->query($uid)->findOne(['where' => ['id' => $id], 'select' => ['documentId', 'locale']]);
        if ($entry === null) {
            return null;
        }

        return $this->strapi->documents($uid)->update([...$params, 'documentId' => $entry['documentId'], 'locale' => $entry['locale'] ?? null]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function delete(string $uid, int|string $id, array $params = []): ?array
    {
        $this->warn();
        $entry = $this->strapi->db()->query($uid)->findOne(['where' => ['id' => $id], 'select' => ['documentId', 'locale']]);
        if ($entry === null) {
            return null;
        }

        $result = $this->strapi->documents($uid)->delete([...$params, 'documentId' => $entry['documentId'], 'locale' => $entry['locale'] ?? null]);

        return $result['entries'][0] ?? null;
    }
}
