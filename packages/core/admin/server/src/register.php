<?php

declare(strict_types=1);

namespace Strapi\Admin;

use Strapi\Admin\Migrations\Database\MigratePreferedLanguageDkToDa;
use Strapi\Admin\Routes\ServeAdminPanel;
use Strapi\Admin\Strategies\Admin as AdminAuthStrategy;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;

/**
 * Port of server/src/register.ts.
 *
 * Not ported yet (later work): the `admin-token` (admin) and `content-api-token` (content-api)
 * auth strategies and the `ai.admin` service. Until the content-api-token strategy is
 * registered, core's PHP-port fallback keeps the content API public (see core's Auth service).
 */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->db()->migrations->internal->register(MigratePreferedLanguageDkToDa::migration());

        $passportMiddleware = Utils::getService($strapi, 'passport')->init();

        $strapi->server()->api('admin')->use($passportMiddleware);
        $strapi->get('auth')->register('admin', AdminAuthStrategy::strategy($strapi));
        // PLACEHOLDER: strategies/admin-token.ts (admin) and strategies/content-api-token.ts
        // (content-api) are not ported yet.

        // PLACEHOLDER: `strapi.add('ai.admin', () => createAiAdminService({ strapi }))` (ai/services/ai.ts) is not ported yet.

        $shouldServeAdminPanel = $strapi->config()->get('admin.serveAdminPanel');

        if ($shouldServeAdminPanel) {
            ServeAdminPanel::registerAdminPanelRoute($strapi);
        }
    }
}
