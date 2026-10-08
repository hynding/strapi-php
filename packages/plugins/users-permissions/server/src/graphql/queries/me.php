<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Queries;

use Strapi\Plugin\Graphql\GraphqlContext;

/** Port of server/src/graphql/queries/me.js */
final class Me
{
    /** @return array<string, mixed> */
    public static function create(): array
    {
        return [
            'type' => 'UsersPermissionsMe',

            'args' => [],

            'resolve' => static function (mixed $parent, mixed $args, mixed $context): mixed {
                $user = GraphqlContext::stateOf($context)?->user();

                if ($user === null || $user === []) {
                    throw new \Exception('Authentication requested');
                }

                return $user;
            },
        ];
    }
}
