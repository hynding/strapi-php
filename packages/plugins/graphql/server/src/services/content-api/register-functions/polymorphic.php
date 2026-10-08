<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\UnionDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/content-api/register-functions/polymorphic.ts */
final class Polymorphic
{
    /** @param array{registry: TypeRegistry, strapi: Strapi} $context */
    public static function registerPolymorphicContentType(Schema $contentType, array $context): void
    {
        ['registry' => $registry, 'strapi' => $strapi] = $context;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $naming = $utils->naming;

        $attributes = $contentType->attributes;

        // For each one of those polymorphic attribute
        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;
            // Isolate its polymorphic attributes
            if (!$utils->attributes->isMorphRelation($attribute)) {
                continue;
            }

            $name = $naming->getMorphRelationTypeName($contentType, $attributeName);
            $target = $attribute['target'] ?? null;

            // Ignore those whose target is not an array
            if (!is_array($target)) {
                continue;
            }

            // Transform target UIDs into types names
            $members = [];
            foreach ($target as $uid) {
                // Get content types definitions
                $model = $strapi->getModel((string) $uid);
                if ($model !== null) {
                    // Resolve types names
                    $members[] = $naming->getTypeName($model);
                }
            }

            // Register the new polymorphic union type
            $registry->register(
                $name,

                Nexus::unionType([
                    'name' => $name,

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

                    'definition' => static function (UnionDefinitionBlock $t) use ($members): void {
                        $t->members(...$members);
                    },
                ]),

                ['kind' => Constants::KINDS['morph'], 'contentType' => $contentType, 'attributeName' => $attributeName],
            );
        }
    }
}
