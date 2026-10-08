<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Mutations;
use Strapi\Plugin\UsersPermissions\Graphql\Queries\Queries;
use Strapi\Plugin\UsersPermissions\Graphql\Types\Types;

/**
 * Port of server/src/graphql/index.js: the users-permissions extension of the GraphQL content
 * API (auth mutations, `me`, the user / role CRUD replacements and their scopes).
 */
final class Graphql
{
    public function __invoke(Strapi $strapi): void
    {
        $graphql = $strapi->plugin('graphql');
        $extensionService = $graphql->service('extension');
        \assert($extensionService instanceof Extension);

        $isShadowCRUDEnabled = $graphql->config('shadowCRUD', true);

        if (!$isShadowCRUDEnabled) {
            return;
        }

        // Disable Permissions queries & mutations but allow the
        // type to be used/selected in filters or nested resolvers
        $extensionService
            ->shadowCRUD('plugin::users-permissions.permission')
            ->disableQueries()
            ->disableMutations();

        // Disable User & Role's Create/Update/Delete actions so they can be replaced
        $actionsToDisable = ['create', 'update', 'delete'];

        $extensionService->shadowCRUD('plugin::users-permissions.user')->disableActions($actionsToDisable);
        $extensionService->shadowCRUD('plugin::users-permissions.role')->disableActions($actionsToDisable);

        // Register new types & resolvers config
        $extensionService->use(static function (array $params) use ($strapi): array {
            $nexus = $params['nexus'] ?? new Nexus();
            \assert($nexus instanceof Nexus);
            $context = ['strapi' => $strapi, 'nexus' => $nexus];

            $types = Types::create($context);
            $queries = Queries::create($context);
            $mutations = Mutations::create($context);
            $resolversConfig = ResolversConfigs::create($context);

            return [
                'types' => [$types, $queries, $mutations],

                'resolversConfig' => $resolversConfig,
            ];
        });
    }
}
