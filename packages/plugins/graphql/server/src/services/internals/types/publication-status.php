<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\EnumTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/types/publication-status.ts */
final class PublicationStatus
{
    /** @return array{PublicationStatus: EnumTypeDef} */
    public static function create(): array
    {
        return [
            /**
             * An enum type definition representing a publication status
             */
            'PublicationStatus' => Nexus::enumType([
                'name' => Constants::PUBLICATION_STATUS_TYPE_NAME,

                'members' => [
                    'DRAFT' => 'draft',
                    'PUBLISHED' => 'published',
                ],
            ]),
        ];
    }
}
