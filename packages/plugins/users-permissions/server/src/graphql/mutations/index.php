<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;

/** Port of server/src/graphql/mutations/index.js */
final class Mutations
{
    private const string USER_UID = 'plugin::users-permissions.user';

    private const string ROLE_UID = 'plugin::users-permissions.role';

    /** @param array{nexus: Nexus, strapi: Strapi} $context */
    public static function create(array $context): ExtendTypeDef
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $naming = $utils->naming;

        $user = $strapi->getModel(self::USER_UID);
        $role = $strapi->getModel(self::ROLE_UID);
        \assert($user !== null && $role !== null);

        $mutations = [
            // CRUD (user & role)
            $naming->getCreateMutationTypeName($role) => Crud\Role\CreateRole::create(...),
            $naming->getUpdateMutationTypeName($role) => Crud\Role\UpdateRole::create(...),
            $naming->getDeleteMutationTypeName($role) => Crud\Role\DeleteRole::create(...),
            $naming->getCreateMutationTypeName($user) => Crud\User\CreateUser::create(...),
            $naming->getUpdateMutationTypeName($user) => Crud\User\UpdateUser::create(...),
            $naming->getDeleteMutationTypeName($user) => Crud\User\DeleteUser::create(...),

            // Other mutations
            'login' => Auth\Login::create(...),
            'register' => Auth\Register::create(...),
            'forgotPassword' => Auth\ForgotPassword::create(...),
            'resetPassword' => Auth\ResetPassword::create(...),
            'changePassword' => Auth\ChangePassword::create(...),
            'emailConfirmation' => Auth\EmailConfirmation::create(...),
        ];

        return $nexus->extendType([
            'type' => 'Mutation',

            'definition' => static function (OutputDefinitionBlock $t) use ($mutations, $context): void {
                foreach ($mutations as $name => $getConfig) {
                    $config = $getConfig($context);

                    $t->field((string) $name, $config);
                }
            },
        ]);
    }
}
