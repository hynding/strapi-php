<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Service;

/** Port of core-api/service/collection-type.ts (`CollectionTypeService`). */
class CollectionType extends CoreService
{
    /**
     * @param array<string, mixed> $params
     * @return array{results: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function find(array $params = []): array
    {
        $uid = $this->contentType->uid;

        $fetchParams = $this->getFetchParams($params);

        $paginationInfo = Pagination::getPaginationInfo($this->strapi, $fetchParams);
        $isPaged = Pagination::isPagedPagination(is_array($fetchParams['pagination'] ?? null) ? $fetchParams['pagination'] : null);

        $results = $this->strapi->documents($uid)->findMany([...$fetchParams, ...$paginationInfo]);

        if (Pagination::shouldCount($this->strapi, $fetchParams)) {
            $count = $this->strapi->documents($uid)->count([...$fetchParams, ...$paginationInfo]);

            return ['results' => $results, 'pagination' => Pagination::transformPaginationResponse($paginationInfo, $count, $isPaged)];
        }

        return ['results' => $results, 'pagination' => Pagination::transformPaginationResponse($paginationInfo, null, $isPaged)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function findOne(string $documentId, array $params = []): ?array
    {
        return $this->strapi->documents($this->contentType->uid)->findOne([...$this->getFetchParams($params), 'documentId' => $documentId]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params = ['data' => []]): array
    {
        return $this->strapi->documents($this->contentType->uid)->create($this->getFetchParams($params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function update(string $documentId, array $params = ['data' => []]): ?array
    {
        return $this->strapi->documents($this->contentType->uid)->update([...$this->getFetchParams($params), 'documentId' => $documentId]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{deletedEntries: int}
     */
    public function delete(string $documentId, array $params = []): array
    {
        ['entries' => $entries] = $this->strapi->documents($this->contentType->uid)->delete([...$this->getFetchParams($params), 'documentId' => $documentId]);

        return ['deletedEntries' => count($entries)];
    }
}
