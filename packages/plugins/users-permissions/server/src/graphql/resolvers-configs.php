<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Utils\Utils as GraphqlUtils;

/** Port of server/src/graphql/resolvers-configs.js */
final class ResolversConfigs
{
    private const string USER_UID = 'plugin::users-permissions.user';

    private const string ROLE_UID = 'plugin::users-permissions.role';

    /**
     * @param array{strapi: Strapi} $context
     * @return array<string, array<string, mixed>>
     */
    public static function create(array $context): array
    {
        $strapi = $context['strapi'];
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof GraphqlUtils);
        $naming = $utils->naming;

        $user = $strapi->getModel(self::USER_UID);
        $role = $strapi->getModel(self::ROLE_UID);
        \assert($user !== null && $role !== null);

        $createRole = $naming->getCreateMutationTypeName($role);
        $updateRole = $naming->getUpdateMutationTypeName($role);
        $deleteRole = $naming->getDeleteMutationTypeName($role);
        $createUser = $naming->getCreateMutationTypeName($user);
        $updateUser = $naming->getUpdateMutationTypeName($user);
        $deleteUser = $naming->getDeleteMutationTypeName($user);

        $userUID = self::USER_UID;
        $roleUID = self::ROLE_UID;

        return [
            // Disabled auth for some operations
            'Mutation.login' => ['auth' => false],
            'Mutation.register' => ['auth' => false],
            'Mutation.forgotPassword' => ['auth' => false],
            'Mutation.resetPassword' => ['auth' => false],
            'Mutation.emailConfirmation' => ['auth' => false],
            'Mutation.changePassword' => [
                'auth' => [
                    'scope' => 'plugin::users-permissions.auth.changePassword',
                ],
            ],

            // Scoped auth for replaced CRUD operations
            // Role
            "Mutation.{$createRole}" => ['auth' => ['scope' => ["{$roleUID}.createRole"]]],
            "Mutation.{$updateRole}" => ['auth' => ['scope' => ["{$roleUID}.updateRole"]]],
            "Mutation.{$deleteRole}" => ['auth' => ['scope' => ["{$roleUID}.deleteRole"]]],
            // User
            "Mutation.{$createUser}" => ['auth' => ['scope' => ["{$userUID}.create"]]],
            "Mutation.{$updateUser}" => ['auth' => ['scope' => ["{$userUID}.update"]]],
            "Mutation.{$deleteUser}" => ['auth' => ['scope' => ["{$userUID}.destroy"]]],
        ];
    }
}
