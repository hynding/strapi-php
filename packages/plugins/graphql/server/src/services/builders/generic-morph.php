<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\UnionDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\UnionTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;

/** Port of server/src/services/builders/generic-morph.ts */
final class GenericMorph
{
    public function __construct(private readonly Strapi $strapi, private readonly TypeRegistry $registry)
    {
    }

    public function buildGenericMorphDefinition(): UnionTypeDef
    {
        $strapi = $this->strapi;
        $registry = $this->registry;
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $naming = $utils->naming;
        $KINDS = Constants::KINDS;

        return Nexus::unionType([
            'name' => Constants::GENERIC_MORPH_TYPENAME,

            'resolveType' => static function (mixed $obj) use ($strapi, $naming): ?string {
                $contentType = $strapi->getModel(is_array($obj) ? (string) ($obj['__type'] ?? '') : '');

                if ($contentType === null) {
                    return null;
                }

                if ($contentType->modelType === 'component') {
                    return $naming->getComponentName($contentType);
                }

                return $naming->getTypeName($contentType);
            },

            'definition' => static function (UnionDefinitionBlock $t) use ($registry, $KINDS): void {
                $members = array_map(
                    // Only keep their name (the type's id)
                    static fn (array $def): string => $def['name'],
                    // Resolve every content-type or component
                    $registry->where(static fn (array $def): bool => in_array($def['config']['kind'] ?? null, [$KINDS['type'], $KINDS['component']], true)),
                );

                $t->members(...$members);
            },
        ]);
    }
}
