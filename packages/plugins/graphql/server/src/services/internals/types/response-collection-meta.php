<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/types/response-collection-meta.ts */
final class ResponseCollectionMeta
{
    /** @return array{ResponseCollectionMeta: ObjectTypeDef} */
    public static function create(Strapi $strapi): array
    {
        return [
            /**
             * A shared type definition used in EntitiesResponseCollection
             * to have information about the collection as a whole
             */
            'ResponseCollectionMeta' => Nexus::objectType([
                'name' => Constants::RESPONSE_COLLECTION_META_TYPE_NAME,

                'definition' => static function (OutputDefinitionBlock $t) use ($strapi): void {
                    $builders = $strapi->plugin('graphql')->service('builders');
                    \assert($builders instanceof Builders);
                    $contentApiBuilders = $builders->get('content-api');
                    if ($contentApiBuilders === null) {
                        throw new \RuntimeException('The content-api builders are not initialized');
                    }

                    $t->nonNull->field('pagination', [
                        'type' => Constants::PAGINATION_TYPE_NAME,
                        'resolve' => $contentApiBuilders->resolvePagination(...),
                    ]);
                },
            ]),
        ];
    }
}
