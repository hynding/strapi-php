<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\Documents;

/**
 * strapi.documents(uid) — the Document Service API (Strapi 5). Mirrors
 * packages/core/types/src/modules/documents/service-instance.ts.
 *
 * @phpstan-type DocParams array{documentId?: string, locale?: string|null, status?: 'draft'|'published', fields?: mixed, filters?: array<string, mixed>, populate?: mixed, sort?: mixed, limit?: int, start?: int, page?: int, pageSize?: int, data?: array<string, mixed>}
 */
interface Repository
{
    /** @param DocParams $params  @return list<array<string, mixed>> */
    public function findMany(array $params = []): array;

    /** @param DocParams $params  @return array<string, mixed>|null */
    public function findFirst(array $params = []): ?array;

    /** @param DocParams $params  @return array<string, mixed>|null */
    public function findOne(array $params): ?array;

    /** @param DocParams $params  @return array<string, mixed> */
    public function create(array $params): array;

    /** @param DocParams $params  @return array<string, mixed>|null */
    public function update(array $params): ?array;

    /** @param DocParams $params  @return array{documentId: string, entries: list<array<string, mixed>>} */
    public function delete(array $params): array;

    /** @param DocParams $params */
    public function count(array $params = []): int;

    /** @param DocParams $params  @return array{documentId: string, entries: list<array<string, mixed>>} */
    public function publish(array $params): array;

    /** @param DocParams $params  @return array{documentId: string, entries: list<array<string, mixed>>} */
    public function unpublish(array $params): array;

    /** @param DocParams $params  @return array{documentId: string, entries: list<array<string, mixed>>} */
    public function discardDraft(array $params): array;
}
