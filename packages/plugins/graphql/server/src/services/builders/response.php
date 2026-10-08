<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/response.ts */
final class Response
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Build a type definition for a content API response for a given content type
     */
    public function buildResponseDefinition(Schema $contentType): ObjectTypeDef
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $name = $utils->naming->getEntityResponseName($contentType);
        $typeName = $utils->naming->getTypeName($contentType);

        return Nexus::objectType([
            'name' => $name,

            'definition' => static function (OutputDefinitionBlock $t) use ($typeName): void {
                $t->field('data', [
                    'type' => $typeName,

                    'resolve' => static fn (mixed $parent): mixed => is_array($parent) ? ($parent['value'] ?? null) : null,
                ]);
            },
        ]);
    }
}
