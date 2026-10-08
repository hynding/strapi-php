<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\EnumTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/types/publication-filter.ts */
final class PublicationFilter
{
    /** @return array{PublicationFilter: EnumTypeDef} */
    public static function create(): array
    {
        return [
            'PublicationFilter' => Nexus::enumType([
                'name' => Constants::PUBLICATION_FILTER_TYPE_NAME,
                'members' => [
                    'NEVER_PUBLISHED' => 'never-published',
                    'HAS_PUBLISHED_VERSION' => 'has-published-version',
                    'MODIFIED' => 'modified',
                    'UNMODIFIED' => 'unmodified',
                    'NEVER_PUBLISHED_DOCUMENT' => 'never-published-document',
                    'HAS_PUBLISHED_VERSION_DOCUMENT' => 'has-published-version-document',
                    'PUBLISHED_WITHOUT_DRAFT' => 'published-without-draft',
                    'PUBLISHED_WITH_DRAFT' => 'published-with-draft',
                ],
            ]),
        ];
    }
}
