<?php

declare(strict_types=1);

namespace Strapi\Database\EntityManager;

use Strapi\Database\Database;
use Strapi\Types\Modules\Database\EntityRepository as EntityRepositoryContract;

/**
 * Port of packages/core/database/src/entity-manager/entity-repository.ts: `db.query(uid)`.
 *
 * @phpstan-import-type FindParams from \Strapi\Types\Modules\Database\Database
 */
final class EntityRepository implements EntityRepositoryContract
{
    public function __construct(public readonly string $uid, private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $params  @return array{0: array<string, mixed>, 1: array{page: int, pageSize: int}} */
    private static function withOffsetLimit(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);
        unset($params['page'], $params['pageSize']);

        $offset = max($page - 1, 0) * $pageSize;

        return [[...$params, 'limit' => $pageSize, 'offset' => $offset], ['page' => $page, 'pageSize' => $pageSize]];
    }

    public function findOne(array $params = []): ?array
    {
        return $this->db->entityManager->findOne($this->uid, $params);
    }

    public function findMany(array $params = []): array
    {
        return $this->db->entityManager->findMany($this->uid, $params);
    }

    /** @param FindParams $params  @return array{0: list<array<string, mixed>>, 1: int} */
    public function findWithCount(array $params = []): array
    {
        return [
            $this->db->entityManager->findMany($this->uid, $params),
            $this->db->entityManager->count($this->uid, $params),
        ];
    }

    public function findPage(array $params = []): array
    {
        [$query, ['page' => $page, 'pageSize' => $pageSize]] = self::withOffsetLimit($params);

        $results = $this->db->entityManager->findMany($this->uid, $query);
        $total = $this->db->entityManager->count($this->uid, $query);

        return [
            'results' => $results,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'pageCount' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'total' => $total,
            ],
        ];
    }

    public function create(array $params): array
    {
        return $this->db->entityManager->create($this->uid, $params);
    }

    public function createMany(array $params): array
    {
        return $this->db->entityManager->createMany($this->uid, $params);
    }

    public function update(array $params): ?array
    {
        return $this->db->entityManager->update($this->uid, $params);
    }

    public function updateMany(array $params): array
    {
        return $this->db->entityManager->updateMany($this->uid, $params);
    }

    public function delete(array $params): ?array
    {
        return $this->db->entityManager->delete($this->uid, $params);
    }

    public function deleteMany(array $params = []): array
    {
        return $this->db->entityManager->deleteMany($this->uid, $params);
    }

    public function count(array $params = []): int
    {
        return $this->db->entityManager->count($this->uid, $params);
    }

    /** Clones an entity (upstream `clone` is a document-service concern; kept for API parity). @param array<string, mixed> $params */
    public function clone(int|string $id, array $params = []): ?array
    {
        return $this->db->entityManager->clone($this->uid, $id, $params);
    }

    /** @param array<string, mixed> $data */
    public function attachRelations(int|string $id, array $data): void
    {
        $trx = $this->db->transaction();
        try {
            $this->db->entityManager->attachRelations($this->uid, $id, $data, ['transaction' => $trx->get()]);
            $trx->commit();
        } catch (\Throwable $e) {
            $trx->rollback();
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function updateRelations(int|string $id, array $data): void
    {
        $trx = $this->db->transaction();
        try {
            $this->db->entityManager->updateRelations($this->uid, $id, $data, ['transaction' => $trx->get()]);
            $trx->commit();
        } catch (\Throwable $e) {
            $trx->rollback();
            throw $e;
        }
    }

    public function deleteRelations(int|string $id): void
    {
        $this->db->entityManager->deleteRelations($this->uid, $id);
    }

    /** @param array<string, mixed> $entity  @return array<string, mixed> */
    public function populate(array $entity, mixed $populate): array
    {
        return $this->db->entityManager->populate($this->uid, $entity, $populate);
    }

    public function load(array $entity, string|array $field, array $params = []): mixed
    {
        return $this->db->entityManager->load($this->uid, $entity, $field, $params === [] ? null : $params);
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $params
     *
     * @return array{results: list<array<string, mixed>>, pagination: array{page: int, pageSize: int, pageCount: int, total: int}}
     */
    public function loadPages(array $entity, string $field, array $params = []): array
    {
        $attribute = $this->db->metadata->get($this->uid)['attributes'][$field] ?? null;

        if ($attribute === null || ($attribute['type'] ?? null) !== 'relation' || !in_array($attribute['relation'] ?? null, ['oneToMany', 'manyToMany'], true)) {
            throw new \InvalidArgumentException("Invalid load. Expected {$field} to be an anyToMany relational attribute");
        }

        [$query, ['page' => $page, 'pageSize' => $pageSize]] = self::withOffsetLimit($params);

        $results = $this->db->entityManager->load($this->uid, $entity, $field, $query);
        $total = (int) ($this->db->entityManager->load($this->uid, $entity, $field, [...$query, 'count' => true])['count'] ?? 0);

        return [
            'results' => $results ?? [],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'pageCount' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'total' => $total,
            ],
        ];
    }
}
