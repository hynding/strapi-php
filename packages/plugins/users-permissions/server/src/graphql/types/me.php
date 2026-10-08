<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/graphql/types/me.js */
final class Me
{
    /** @param array{nexus: Nexus} $context */
    public static function create(array $context): NamedTypeDef
    {
        return $context['nexus']->objectType([
            'name' => 'UsersPermissionsMe',

            'definition' => static function (OutputDefinitionBlock $t): void {
                $t->nonNull->id('id');
                $t->nonNull->id('documentId');
                $t->nonNull->string('username');
                $t->string('email');
                $t->boolean('confirmed');
                $t->boolean('blocked');
                $t->field('role', ['type' => 'UsersPermissionsMeRole']);
            },
        ]);
    }
}
