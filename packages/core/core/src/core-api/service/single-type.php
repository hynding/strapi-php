<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Service;

/** Port of core-api/service/single-type.ts (`SingleTypeService`). */
class SingleType extends CoreService
{
    public function getDocumentId(): ?string
    {
        $document = $this->strapi->db()->query($this->contentType->uid)->findOne();

        return $document !== null && isset($document['documentId']) ? (string) $document['documentId'] : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function find(array $params = []): ?array
    {
        return $this->strapi->documents($this->contentType->uid)->findFirst($this->getFetchParams($params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function createOrUpdate(array $params = []): ?array
    {
        $uid = $this->contentType->uid;

        $documentId = $this->getDocumentId();

        if ($documentId !== null) {
            return $this->strapi->documents($uid)->update([...$this->getFetchParams($params), 'documentId' => $documentId]);
        }

        return $this->strapi->documents($uid)->create($this->getFetchParams($params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{deletedEntries: int}
     */
    public function delete(array $params = []): array
    {
        $documentId = $this->getDocumentId();
        if ($documentId === null) {
            return ['deletedEntries' => 0];
        }

        ['entries' => $entries] = $this->strapi->documents($this->contentType->uid)->delete([...$this->getFetchParams($params), 'documentId' => $documentId]);

        return ['deletedEntries' => count($entries)];
    }
}
