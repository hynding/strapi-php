<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Core\Strapi;

/** Port of server/src/services/builders/resolvers/index.ts */
final class Resolvers
{
    // Generics
    public readonly Association $association;

    // Builders
    public readonly Query $query;

    public readonly Component $component;

    public readonly DynamicZone $dynamicZone;

    public readonly Pagination $pagination;

    public function __construct(Strapi $strapi)
    {
        $this->association = new Association($strapi);
        $this->query = new Query($strapi);
        $this->component = new Component($strapi);
        $this->dynamicZone = new DynamicZone($strapi);
        $this->pagination = new Pagination($strapi);
    }
}
