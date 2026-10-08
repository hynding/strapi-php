<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Strategies\UsersPermissions as AuthStrategy;
use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;
use Strapi\Plugin\UsersPermissions\Utils\Sanitize\Sanitizers;

/**
 * Port of server/src/register.js.
 *
 * Not ported: the GraphQL extension (`server/src/graphql`, loaded when the graphql plugin is
 * installed; strapi/plugin-graphql is not ported yet).
 */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->get('auth')->register('content-api', AuthStrategy::strategy($strapi));
        $strapi->sanitizers()->add('content-api.output', Sanitizers::defaultSanitizeOutputSanitizer($strapi));

        // the providers' HTTP calls (upstream: the global `fetch`) go through core's `strapi.fetch`
        ProviderHttp::$defaultFetch = $strapi->fetch();

        if ($strapi->hasPlugin('graphql')) {
            // server/src/graphql is not ported (the graphql plugin is not ported yet)
            $strapi->log()->warning('[users-permissions] the GraphQL extension is not ported: no users-permissions GraphQL types or resolvers are registered');
        }

        if ($strapi->hasPlugin('documentation')) {
            $specPath = dirname(__DIR__, 2) . '/documentation/content-api.yaml';
            $spec = (string) file_get_contents($specPath);

            $registerOverride = [$strapi->plugin('documentation')->service('override'), 'registerOverride'];
            if (!is_callable($registerOverride)) {
                throw new \RuntimeException('The documentation override service has no registerOverride method');
            }

            $registerOverride($spec, [
                'pluginOrigin' => 'users-permissions',
                'excludeFromGeneration' => ['users-permissions'],
            ]);
        }
    }
}
