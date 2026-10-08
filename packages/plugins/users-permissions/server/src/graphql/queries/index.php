<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Queries;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/graphql/queries/index.js */
final class Queries
{
    /** @param array{nexus: Nexus} $context */
    public static function create(array $context): ExtendTypeDef
    {
        return $context['nexus']->extendType([
            'type' => 'Query',

            'definition' => static function (OutputDefinitionBlock $t): void {
                $t->field('me', Me::create());
            },
        ]);
    }
}
