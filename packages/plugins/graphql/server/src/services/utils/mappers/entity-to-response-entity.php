<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils\Mappers;

/** Port of server/src/services/utils/mappers/entity-to-response-entity.ts */
final class EntityToResponseEntity
{
    /**
     * @param array<string, mixed> $entity
     * @return array{id: mixed, attributes: array<string, mixed>}
     */
    public function entityToResponseEntity(array $entity): array
    {
        return ['id' => $entity['id'] ?? null, 'attributes' => $entity];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array{id: mixed, attributes: array<string, mixed>}>
     */
    public function entitiesToResponseEntities(array $entities): array
    {
        return array_map(fn (array $entity): array => $this->entityToResponseEntity($entity), $entities);
    }
}
