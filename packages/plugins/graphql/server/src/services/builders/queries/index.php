<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Queries;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/queries/index.ts */
final class Queries
{
    private readonly CollectionType $collectionType;

    private readonly SingleType $singleType;

    public function __construct(Strapi $strapi)
    {
        $this->collectionType = new CollectionType($strapi);
        $this->singleType = new SingleType($strapi);
    }

    public function buildCollectionTypeQueries(Schema $contentType): ExtendTypeDef
    {
        return $this->collectionType->buildCollectionTypeQueries($contentType);
    }

    public function buildSingleTypeQueries(Schema $contentType): ExtendTypeDef
    {
        return $this->singleType->buildSingleTypeQueries($contentType);
    }
}
