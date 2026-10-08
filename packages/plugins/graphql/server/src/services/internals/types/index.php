<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/types/index.ts: `buildInternalTypes()` */
final class Types
{
    /** @return array<string, array<string, mixed>> kind => [name => definition] */
    public static function buildInternalTypes(Strapi $strapi): array
    {
        $KINDS = Constants::KINDS;

        return [
            $KINDS['internal'] => [
                'error' => Error::create(),
                'pagination' => Pagination::create(),
                'responseCollectionMeta' => ResponseCollectionMeta::create($strapi),
                'deleteDocumentResponse' => DeleteMutationResponse::create(),
            ],

            $KINDS['enum'] => [
                'publicationStatus' => PublicationStatus::create(),
                'publicationFilter' => PublicationFilter::create(),
            ],

            $KINDS['filtersInput'] => [
                ...Filters::create($strapi),
            ],
        ];
    }
}
