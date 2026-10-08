<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/types/pagination.ts */
final class Pagination
{
    /** @return array{Pagination: ObjectTypeDef} */
    public static function create(): array
    {
        return [
            /**
             * Type definition for a Pagination object
             */
            'Pagination' => Nexus::objectType([
                'name' => Constants::PAGINATION_TYPE_NAME,

                'definition' => static function (OutputDefinitionBlock $t): void {
                    $t->nonNull->int('total');
                    $t->nonNull->int('page');
                    $t->nonNull->int('pageSize');
                    $t->nonNull->int('pageCount');
                },
            ]),
        ];
    }
}
