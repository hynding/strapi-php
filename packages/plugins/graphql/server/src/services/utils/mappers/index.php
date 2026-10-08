<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils\Mappers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\Filters\Operators\Operator;
use Strapi\Types\Schema\Schema;

/**
 * Port of server/src/services/utils/mappers/index.ts: the four mappers merged into one object.
 */
final class Mappers
{
    private readonly StrapiScalarToGraphqlScalar $strapiScalarToGraphqlScalar;

    private readonly GraphqlFiltersToStrapiQuery $graphqlFiltersToStrapiQuery;

    private readonly GraphqlScalarToOperators $graphqlScalarToOperators;

    private readonly EntityToResponseEntity $entityToResponseEntity;

    public function __construct(Strapi $strapi)
    {
        $this->strapiScalarToGraphqlScalar = new StrapiScalarToGraphqlScalar();
        $this->graphqlFiltersToStrapiQuery = new GraphqlFiltersToStrapiQuery($strapi);
        $this->graphqlScalarToOperators = new GraphqlScalarToOperators($strapi);
        $this->entityToResponseEntity = new EntityToResponseEntity();
    }

    public function strapiScalarToGraphQLScalar(mixed $strapiScalar): ?string
    {
        return $this->strapiScalarToGraphqlScalar->strapiScalarToGraphQLScalar($strapiScalar);
    }

    public function graphQLFiltersToStrapiQuery(mixed $filters, ?Schema $contentType): mixed
    {
        return $this->graphqlFiltersToStrapiQuery->graphQLFiltersToStrapiQuery($filters, $contentType);
    }

    /** @return list<Operator>|null */
    public function graphqlScalarToOperators(string $graphqlScalar): ?array
    {
        return $this->graphqlScalarToOperators->graphqlScalarToOperators($graphqlScalar);
    }

    /**
     * @param array<string, mixed> $entity
     * @return array{id: mixed, attributes: array<string, mixed>}
     */
    public function entityToResponseEntity(array $entity): array
    {
        return $this->entityToResponseEntity->entityToResponseEntity($entity);
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array{id: mixed, attributes: array<string, mixed>}>
     */
    public function entitiesToResponseEntities(array $entities): array
    {
        return $this->entityToResponseEntity->entitiesToResponseEntities($entities);
    }
}
