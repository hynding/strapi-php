<?php

declare(strict_types=1);

namespace Strapi\ContentManager;

use Strapi\ContentManager\Constants\Constants;
use Strapi\ContentManager\Mcp\RegisterContentManagerMcpTools;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;

/**
 * Port of server/src/bootstrap.ts. `history.bootstrap` / `preview.bootstrap` (Enterprise licence)
 * are not ported.
 */
final class Bootstrap
{
    public function __invoke(Strapi $strapi): void
    {
        foreach (Constants::ALLOWED_WEBHOOK_EVENTS as $key => $value) {
            $strapi->get('webhookStore')->addAllowedEvent($key, $value);
        }

        Utils::getService($strapi, 'field-sizes')->setCustomFieldInputSizes();
        Utils::getService($strapi, 'components')->syncConfigurations();
        Utils::getService($strapi, 'content-types')->syncConfigurations();
        Utils::getService($strapi, 'permission')->registerPermissions();

        RegisterContentManagerMcpTools::registerContentManagerMcpTools($strapi);
    }
}
