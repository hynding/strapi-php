<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Services\DocumentService\Middlewares\MiddlewareManager;
use Strapi\Types\Modules\Documents\Repository as RepositoryContract;

/**
 * The `middlewares.wrapObject(repository, { uid, contentType }, { exclude })` facade of
 * services/document-service/index.ts: every action runs the document-service middlewares with
 * `ctx = { uid, contentType, action, params }`; `updateComponents` / `omitComponentData` are passed through.
 */
final class DocumentServiceInstance implements RepositoryContract
{
    /** @param array{uid: string, contentType: \Strapi\Types\Schema\Schema} $ctxDefaults */
    public function __construct(
        private readonly Repository $repository,
        private readonly MiddlewareManager $middlewares,
        private readonly array $ctxDefaults,
    ) {
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    /** @param array<string, mixed> $params */
    private function run(string $action, array $params): mixed
    {
        $ctx = [...$this->ctxDefaults, 'action' => $action, 'params' => $params];

        return $this->middlewares->run($ctx, fn (array $ctx): mixed => $this->repository->{$action}($ctx['params']));
    }

    public function findMany(array $params = []): array
    {
        return $this->run('findMany', $params);
    }

    public function findFirst(array $params = []): ?array
    {
        return $this->run('findFirst', $params);
    }

    public function findOne(array $params): ?array
    {
        return $this->run('findOne', $params);
    }

    public function create(array $params): array
    {
        return $this->run('create', $params);
    }

    /** @param array<string, mixed> $params @return array{documentId: string|null, entries: list<array<string, mixed>>} */
    public function clone(array $params): array
    {
        return $this->run('clone', $params);
    }

    public function update(array $params): ?array
    {
        return $this->run('update', $params);
    }

    public function delete(array $params): array
    {
        return $this->run('delete', $params);
    }

    public function count(array $params = []): int
    {
        return $this->run('count', $params);
    }

    public function publish(array $params): array
    {
        return $this->run('publish', $params);
    }

    public function unpublish(array $params): array
    {
        return $this->run('unpublish', $params);
    }

    public function discardDraft(array $params): array
    {
        return $this->run('discardDraft', $params);
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $data @return array<string, mixed> */
    public function updateComponents(array $entry, array $data): array
    {
        return $this->repository->updateComponents($entry, $data);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function omitComponentData(array $data): array
    {
        return $this->repository->omitComponentData($data);
    }
}
