<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Mutations;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/mutations/index.ts */
final class Mutations
{
    private readonly CollectionType $collectionType;

    private readonly SingleType $singleType;

    public function __construct(Strapi $strapi)
    {
        $this->collectionType = new CollectionType($strapi);
        $this->singleType = new SingleType($strapi);
    }

    public function buildCollectionTypeMutations(Schema $contentType): ExtendTypeDef
    {
        return $this->collectionType->buildCollectionTypeMutations($contentType);
    }

    public function buildSingleTypeMutations(Schema $contentType): ExtendTypeDef
    {
        return $this->singleType->buildSingleTypeMutations($contentType);
    }
}
