<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Crud\User;

use Strapi\Core\Services\Server\Context as ServerContext;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Plugin\UsersPermissions\Graphql\Utils as GraphqlUtils;

/** Port of server/src/graphql/mutations/crud/user/update-user.js */
final class UpdateUser
{
    private const string USERS_PERMISSIONS_USER_UID = 'plugin::users-permissions.user';

    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return array<string, mixed>
     */
    public static function create(array $context): array
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $userContentType = $strapi->getModel(self::USERS_PERMISSIONS_USER_UID);
        \assert($userContentType !== null);

        $userInputName = $utils->naming->getContentTypeInputName($userContentType);
        $responseName = $utils->naming->getEntityResponseName($userContentType);

        return [
            'type' => $nexus->nonNull($responseName),

            'args' => [
                'id' => $nexus->nonNull('ID'),
                'data' => $nexus->nonNull($userInputName),
            ],

            'description' => 'Update an existing user',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi): array {
                $koaContext = GraphqlContext::koaContextOf($context);
                if (!$koaContext instanceof ServerContext) {
                    throw new \RuntimeException('The GraphQL context has no Koa context');
                }

                $koaContext->setParams(['id' => (string) $args['id']]);
                $koaContext->setRequestBody($args['data']);

                GraphqlUtils::controllerAction($strapi, 'user', 'update')($koaContext);

                GraphqlUtils::checkBadRequest($koaContext->body());

                return [
                    'value' => $koaContext->body(),
                    'info' => ['args' => $args, 'resourceUID' => 'plugin::users-permissions.user'],
                ];
            },
        ];
    }
}
