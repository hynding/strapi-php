<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Types;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/graphql/types/index.js */
final class Types
{
    /**
     * @param array{nexus: Nexus, strapi: Strapi} $context
     * @return list<mixed>
     */
    public static function create(array $context): array
    {
        $typesFactories = [
            Me::create(...),
            MeRole::create(...),
            RegisterInput::create(...),
            LoginInput::create(...),
            PasswordPayload::create(...),
            LoginPayload::create(...),
            CreateRolePayload::create(...),
            UpdateRolePayload::create(...),
            DeleteRolePayload::create(...),
            UserInput::create(...),
        ];

        return array_map(static fn (callable $factory): mixed => $factory($context), $typesFactories);
    }
}
