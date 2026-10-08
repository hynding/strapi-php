<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\EnumTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\UnionTypeDef;
use Strapi\Plugin\Graphql\Services\Builders\Filters\Filters;
use Strapi\Plugin\Graphql\Services\Builders\Mutations\Mutations;
use Strapi\Plugin\Graphql\Services\Builders\Queries\Queries;
use Strapi\Plugin\Graphql\Services\Builders\Resolvers\QueriesResolvers;
use Strapi\Plugin\Graphql\Services\Builders\Resolvers\Resolvers;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Types\Schema\Schema;

/**
 * The object `builders.new(name, registry)` returns: upstream merges every builder factory's
 * methods into one object (`reduce(merge, {})`); this class delegates to each builder
 * (PHP-port addition).
 */
final class BuildersInstance
{
    private readonly Enums $enums;

    private readonly DynamicZones $dynamicZone;

    private readonly Entity $entity;

    private readonly Type $typeBuilder;

    private readonly Response $response;

    private readonly ResponseCollection $responseCollection;

    private readonly RelationResponseCollection $relationResponseCollection;

    private readonly Queries $queries;

    private readonly Mutations $mutations;

    private readonly Filters $filters;

    private readonly Input $inputs;

    private readonly GenericMorph $genericMorph;

    private readonly Resolvers $resolvers;

    public function __construct(Strapi $strapi, TypeRegistry $registry)
    {
        $this->enums = new Enums();
        $this->dynamicZone = new DynamicZones($strapi);
        $this->entity = new Entity($strapi);
        $this->typeBuilder = new Type($strapi);
        $this->response = new Response($strapi);
        $this->responseCollection = new ResponseCollection($strapi);
        $this->relationResponseCollection = new RelationResponseCollection($strapi);
        $this->queries = new Queries($strapi);
        $this->mutations = new Mutations($strapi);
        $this->filters = new Filters($strapi);
        $this->inputs = new Input($strapi);
        $this->genericMorph = new GenericMorph($strapi, $registry);
        $this->resolvers = new Resolvers($strapi);
    }

    /** @param array<string, mixed> $definition */
    public function buildEnumTypeDefinition(array $definition, string $name): EnumTypeDef
    {
        return $this->enums->buildEnumTypeDefinition($definition, $name);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{0: UnionTypeDef, 1: ScalarTypeDef}
     */
    public function buildDynamicZoneDefinition(array $definition, string $name, string $inputName): array
    {
        return $this->dynamicZone->buildDynamicZoneDefinition($definition, $name, $inputName);
    }

    public function buildEntityDefinition(Schema $contentType): ObjectTypeDef
    {
        return $this->entity->buildEntityDefinition($contentType);
    }

    public function buildTypeDefinition(Schema $contentType): ObjectTypeDef
    {
        return $this->typeBuilder->buildTypeDefinition($contentType);
    }

    public function buildResponseDefinition(Schema $contentType): ObjectTypeDef
    {
        return $this->response->buildResponseDefinition($contentType);
    }

    public function buildResponseCollectionDefinition(Schema $contentType): ObjectTypeDef
    {
        return $this->responseCollection->buildResponseCollectionDefinition($contentType);
    }

    public function buildRelationResponseCollectionDefinition(Schema $contentType): ObjectTypeDef
    {
        return $this->relationResponseCollection->buildRelationResponseCollectionDefinition($contentType);
    }

    public function buildCollectionTypeQueries(Schema $contentType): ExtendTypeDef
    {
        return $this->queries->buildCollectionTypeQueries($contentType);
    }

    public function buildSingleTypeQueries(Schema $contentType): ExtendTypeDef
    {
        return $this->queries->buildSingleTypeQueries($contentType);
    }

    public function buildCollectionTypeMutations(Schema $contentType): ExtendTypeDef
    {
        return $this->mutations->buildCollectionTypeMutations($contentType);
    }

    public function buildSingleTypeMutations(Schema $contentType): ExtendTypeDef
    {
        return $this->mutations->buildSingleTypeMutations($contentType);
    }

    public function buildContentTypeFilters(Schema $contentType): InputObjectTypeDef
    {
        return $this->filters->buildContentTypeFilters($contentType);
    }

    public function buildInputType(Schema $contentType): InputObjectTypeDef
    {
        return $this->inputs->buildInputType($contentType);
    }

    public function buildGenericMorphDefinition(): UnionTypeDef
    {
        return $this->genericMorph->buildGenericMorphDefinition();
    }

    /** @param array{contentTypeUID: string, attributeName: string} $options */
    public function buildAssociationResolver(array $options): \Closure
    {
        return $this->resolvers->association->buildAssociationResolver($options);
    }

    /** @param array{contentType: Schema} $options */
    public function buildQueriesResolvers(array $options): QueriesResolvers
    {
        return $this->resolvers->query->buildQueriesResolvers($options);
    }

    /** @param array{contentTypeUID: string, attributeName: string} $options */
    public function buildComponentResolver(array $options): \Closure
    {
        return $this->resolvers->component->buildComponentResolver($options);
    }

    /** @param array{contentTypeUID: string, attributeName: string} $options */
    public function buildDynamicZoneResolver(array $options): \Closure
    {
        return $this->resolvers->dynamicZone->buildDynamicZoneResolver($options);
    }

    /** @return array{total: int, page: int, pageSize: int, pageCount: int} */
    public function resolvePagination(mixed $parent, mixed $_, mixed $ctx): array
    {
        return $this->resolvers->pagination->resolvePagination($parent, $_, $ctx);
    }
}
