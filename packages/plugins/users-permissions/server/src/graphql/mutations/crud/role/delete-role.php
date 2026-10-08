<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Crud\Role;

use Strapi\Core\Services\Server\Context as ServerContext;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\UsersPermissions\Graphql\Utils as GraphqlUtils;

/** Port of server/src/graphql/mutations/crud/role/delete-role.js */
final class DeleteRole
{
    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return array<string, mixed>
     */
    public static function create(array $context): array
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;

        return [
            'type' => 'UsersPermissionsDeleteRolePayload',

            'args' => [
                'id' => $nexus->nonNull('ID'),
            ],

            'description' => 'Delete an existing role',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi): array {
                $koaContext = GraphqlContext::koaContextOf($context);
                if (!$koaContext instanceof ServerContext) {
                    throw new \RuntimeException('The GraphQL context has no Koa context');
                }

                $koaContext->setParams(['role' => (string) $args['id']]);

                GraphqlUtils::controllerAction($strapi, 'role', 'deleteRole')($koaContext);

                return ['ok' => true];
            },
        ];
    }
}
