<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/graphql/types/register-input.js */
final class RegisterInput
{
    /** @param array{nexus: Nexus} $context */
    public static function create(array $context): NamedTypeDef
    {
        return $context['nexus']->inputObjectType([
            'name' => 'UsersPermissionsRegisterInput',

            'definition' => static function (OutputDefinitionBlock $t): void {
                $t->nonNull->string('username');
                $t->nonNull->string('email');
                $t->nonNull->string('password');
            },
        ]);
    }
}
