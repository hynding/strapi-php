<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/filters/index.ts */
final class Filters
{
    private readonly ContentType $contentType;

    public function __construct(Strapi $strapi)
    {
        $this->contentType = new ContentType($strapi);
    }

    public function buildContentTypeFilters(Schema $contentType): InputObjectTypeDef
    {
        return $this->contentType->buildContentTypeFilters($contentType);
    }
}
