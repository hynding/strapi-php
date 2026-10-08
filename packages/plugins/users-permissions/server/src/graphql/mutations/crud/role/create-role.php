<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Crud\Role;

use Strapi\Core\Services\Server\Context as ServerContext;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Plugin\UsersPermissions\Graphql\Utils as GraphqlUtils;

/** Port of server/src/graphql/mutations/crud/role/create-role.js */
final class CreateRole
{
    private const string USERS_PERMISSIONS_ROLE_UID = 'plugin::users-permissions.role';

    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return array<string, mixed>
     */
    public static function create(array $context): array
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $roleContentType = $strapi->getModel(self::USERS_PERMISSIONS_ROLE_UID);
        \assert($roleContentType !== null);

        $roleInputName = $utils->naming->getContentTypeInputName($roleContentType);

        return [
            'type' => 'UsersPermissionsCreateRolePayload',

            'args' => [
                'data' => $nexus->nonNull($roleInputName),
            ],

            'description' => 'Create a new role',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi): array {
                $koaContext = GraphqlContext::koaContextOf($context);
                if (!$koaContext instanceof ServerContext) {
                    throw new \RuntimeException('The GraphQL context has no Koa context');
                }

                $koaContext->setRequestBody($args['data']);

                GraphqlUtils::controllerAction($strapi, 'role', 'createRole')($koaContext);

                return ['ok' => true];
            },
        ];
    }
}
