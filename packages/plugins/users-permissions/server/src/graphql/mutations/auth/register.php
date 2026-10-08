<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\UsersPermissions\Graphql\Utils;
use Strapi\Types\Core\Context;

/** Port of server/src/graphql/mutations/auth/register.js */
final class Register
{
    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return array<string, mixed>
     */
    public static function create(array $context): array
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;
        $runRateLimit = RateLimit::createRateLimitRunner($strapi, '/auth/local/register');

        return [
            'type' => $nexus->nonNull('UsersPermissionsLoginPayload'),

            'args' => [
                'input' => $nexus->nonNull('UsersPermissionsRegisterInput'),
            ],

            'description' => 'Register a user',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi, $runRateLimit): array {
                $koaContext = GraphqlContext::koaContextOf($context);

                $output = $runRateLimit($koaContext, ['body' => $args['input']], static fn (Context $ctx): mixed => Utils::controllerAction($strapi, 'auth', 'register')($ctx));

                Utils::checkBadRequest($output);

                return [
                    'user' => is_array($output) ? ($output['user'] ?? $output) : $output,
                    'jwt' => is_array($output) ? ($output['jwt'] ?? null) : null,
                ];
            },
        ];
    }
}
