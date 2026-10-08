<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Strategies\UsersPermissions as AuthStrategy;
use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;
use Strapi\Plugin\UsersPermissions\Utils\Sanitize\Sanitizers;

/**
 * Port of server/src/register.js.
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
            (new Graphql\Graphql())($strapi);
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
