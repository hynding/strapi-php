<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\UsersPermissions\Graphql\Utils;
use Strapi\Types\Core\Context;

/** Port of server/src/graphql/mutations/auth/forgot-password.js */
final class ForgotPassword
{
    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return array<string, mixed>
     */
    public static function create(array $context): array
    {
        ['nexus' => $nexus, 'strapi' => $strapi] = $context;
        $runRateLimit = RateLimit::createRateLimitRunner($strapi, '/auth/forgot-password');

        return [
            'type' => 'UsersPermissionsPasswordPayload',

            'args' => [
                'email' => $nexus->nonNull('String'),
            ],

            'description' => 'Request a reset password token',

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi, $runRateLimit): array {
                $koaContext = GraphqlContext::koaContextOf($context);

                $output = $runRateLimit($koaContext, ['body' => $args], static fn (Context $ctx): mixed => Utils::controllerAction($strapi, 'auth', 'forgotPassword')($ctx));

                Utils::checkBadRequest($output);

                return [
                    'ok' => is_array($output) ? ($output['ok'] ?? $output) : $output,
                ];
            },
        ];
    }
}
