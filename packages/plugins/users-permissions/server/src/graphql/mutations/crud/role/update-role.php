<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Crud\Role;

use Strapi\Core\Services\Server\Context as ServerContext;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Plugin\UsersPermissions\Graphql\Utils as GraphqlUtils;

/** Port of server/src/graphql/mutations/crud/role/update-role.js */
final class UpdateRole
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
            'type' => 'UsersPermissionsUpdateRolePayload',

            'args' => [
                'id' => $nexus->nonNull('ID'),
                'data' => $nexus->nonNull($roleInputName),
            ],

            'description' => 'Update an existing role',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi): array {
                $koaContext = GraphqlContext::koaContextOf($context);
                if (!$koaContext instanceof ServerContext) {
                    throw new \RuntimeException('The GraphQL context has no Koa context');
                }

                $koaContext->setParams(['role' => (string) $args['id']]);
                $body = is_array($args['data']) ? $args['data'] : [];
                $body['role'] = $args['id'];
                $koaContext->setRequestBody($body);

                GraphqlUtils::controllerAction($strapi, 'role', 'updateRole')($koaContext);

                return ['ok' => true];
            },
        ];
    }
}
