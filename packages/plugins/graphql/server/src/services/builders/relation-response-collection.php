<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/relation-response-collection.ts */
final class RelationResponseCollection
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Build a type definition for a content API relation's collection response for a given content type
     */
    public function buildRelationResponseCollectionDefinition(Schema $contentType): ObjectTypeDef
    {
        $strapi = $this->strapi;
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $name = $utils->naming->getRelationResponseCollectionName($contentType);
        $typeName = $utils->naming->getTypeName($contentType);
        $nodes = static fn (mixed $parent): mixed => (is_array($parent) ? ($parent['nodes'] ?? null) : null) ?? [];

        return Nexus::objectType([
            'name' => $name,

            'definition' => static function (OutputDefinitionBlock $t) use ($strapi, $typeName, $nodes): void {
                $t->nonNull->list->field('nodes', [
                    'type' => Nexus::nonNull($typeName),

                    'resolve' => $nodes,
                ]);

                if ($strapi->plugin('graphql')->config('v4CompatibilityMode', false)) {
                    $t->nonNull->list->field('data', [
                        'deprecation' => 'Use `nodes` field instead',
                        'type' => Nexus::nonNull($typeName),
                        'resolve' => $nodes,
                    ]);
                }
            },
        ]);
    }
}
