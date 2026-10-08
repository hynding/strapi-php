<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Types;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\InputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;

/** Port of server/src/graphql/types/user-input.js */
final class UserInput
{
    private const string USERS_PERMISSIONS_USER_UID = 'plugin::users-permissions.user';

    /** @param array{nexus: Nexus, strapi: Strapi} $context */
    public static function create(array $context): ExtendTypeDef
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $userContentType = $strapi->getModel(self::USERS_PERMISSIONS_USER_UID);
        \assert($userContentType !== null);
        $userInputName = $utils->naming->getContentTypeInputName($userContentType);

        return $nexus->extendInputType([
            'type' => $userInputName,

            'definition' => static function (InputDefinitionBlock $t): void {
                // Manually add the private password field back to the data
                // input type as it is used for CRUD operations on users
                $t->string('password');
            },
        ]);
    }
}
