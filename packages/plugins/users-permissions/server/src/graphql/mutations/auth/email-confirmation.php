<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\UsersPermissions\Graphql\Utils;

/** Port of server/src/graphql/mutations/auth/email-confirmation.js */
final class EmailConfirmation
{
    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return array<string, mixed>
     */
    public static function create(array $context): array
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;

        return [
            'type' => 'UsersPermissionsLoginPayload',

            'args' => [
                'confirmation' => $nexus->nonNull('String'),
            ],

            'description' => 'Confirm an email users email address',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi): array {
                $koaContext = GraphqlContext::koaContextOf($context);
                if ($koaContext === null) {
                    throw new \RuntimeException('The GraphQL context has no Koa context');
                }

                $koaContext->setQuery($args);

                Utils::controllerAction($strapi, 'auth', 'emailConfirmation')($koaContext, null, true);

                $output = $koaContext->body();

                Utils::checkBadRequest($output);

                return [
                    'user' => is_array($output) ? ($output['user'] ?? $output) : $output,
                    'jwt' => is_array($output) ? ($output['jwt'] ?? null) : null,
                ];
            },
        ];
    }
}
