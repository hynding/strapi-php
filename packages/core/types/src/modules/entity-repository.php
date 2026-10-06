<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\Database;

/**
 * strapi.db.query(uid) — mirrors packages/core/database/src/entity-manager/entity-repository.ts.
 *
 * @phpstan-import-type FindParams from Database
 */
interface EntityRepository
{
    /** @param FindParams $params  @return array<string, mixed>|null */
    public function findOne(array $params = []): ?array;

    /** @param FindParams $params  @return list<array<string, mixed>> */
    public function findMany(array $params = []): array;

    /** @param FindParams $params  @return array{results: list<array<string, mixed>>, pagination: array{page: int, pageSize: int, pageCount: int, total: int}} */
    public function findPage(array $params = []): array;

    /** @param FindParams $params */
    public function count(array $params = []): int;

    /** @param array{data: array<string, mixed>, select?: mixed, populate?: mixed} $params  @return array<string, mixed> */
    public function create(array $params): array;

    /** @param array{data: list<array<string, mixed>>} $params  @return array{count: int, ids: list<int>} */
    public function createMany(array $params): array;

    /** @param array{where: array<string, mixed>, data: array<string, mixed>, select?: mixed, populate?: mixed} $params  @return array<string, mixed>|null */
    public function update(array $params): ?array;

    /** @param array{where: array<string, mixed>, data: array<string, mixed>} $params  @return array{count: int} */
    public function updateMany(array $params): array;

    /** @param array{where: array<string, mixed>, select?: mixed, populate?: mixed} $params  @return array<string, mixed>|null */
    public function delete(array $params): ?array;

    /** @param array{where?: array<string, mixed>} $params  @return array{count: int} */
    public function deleteMany(array $params = []): array;

    /** @param array<string, mixed> $params */
    public function load(array $entity, string $field, array $params = []): mixed;
}
