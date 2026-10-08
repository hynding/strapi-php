<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/graphql/types/update-role-payload.js */
final class UpdateRolePayload
{
    /** @param array{nexus: Nexus} $context */
    public static function create(array $context): NamedTypeDef
    {
        return $context['nexus']->objectType([
            'name' => 'UsersPermissionsUpdateRolePayload',

            'definition' => static function (OutputDefinitionBlock $t): void {
                $t->nonNull->boolean('ok');
            },
        ]);
    }
}
