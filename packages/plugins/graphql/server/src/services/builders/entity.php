<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/entity.ts */
final class Entity
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Build a higher level type for a content type which contains the attributes, the ID and the metadata
     */
    public function buildEntityDefinition(Schema $contentType): ObjectTypeDef
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $naming = $utils->naming;

        $attributes = $contentType->attributes;

        $name = $naming->getEntityName($contentType);
        $typeName = $naming->getTypeName($contentType);

        return Nexus::objectType([
            'name' => $name,

            'definition' => static function (OutputDefinitionBlock $t) use ($attributes, $typeName): void {
                // Keep the ID attribute at the top level
                $t->id('id', ['resolve' => static fn (mixed $parent): mixed => is_array($parent) ? ($parent['id'] ?? null) : null]);

                if ($attributes !== []) {
                    // Keep the fetched object into a dedicated `attributes` field
                    $t->field('attributes', [
                        'type' => $typeName,
                        'resolve' => static fn (mixed $parent): mixed => $parent,
                    ]);
                }
            },
        ]);
    }
}
