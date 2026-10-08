<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Args;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\InputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/services/internals/args/pagination.ts */
final class Pagination
{
    private static ?InputObjectTypeDef $paginationInputType = null;

    private static function paginationInputType(): InputObjectTypeDef
    {
        return self::$paginationInputType ??= Nexus::inputObjectType([
            'name' => 'PaginationArg',

            'definition' => static function (InputDefinitionBlock $t): void {
                $t->int('page');
                $t->int('pageSize');
                $t->int('start');
                $t->int('limit');
            },
        ]);
    }

    public static function create(): ArgDef
    {
        return Nexus::arg([
            'type' => self::paginationInputType(),
            'default' => [],
        ]);
    }
}
