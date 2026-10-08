<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/graphql/types/login-input.js */
final class LoginInput
{
    /** @param array{nexus: Nexus} $context */
    public static function create(array $context): NamedTypeDef
    {
        return $context['nexus']->inputObjectType([
            'name' => 'UsersPermissionsLoginInput',

            'definition' => static function (OutputDefinitionBlock $t): void {
                $t->nonNull->string('identifier');
                $t->nonNull->string('password');
                $t->nonNull->string('provider', ['default' => 'local']);
            },
        ]);
    }
}
