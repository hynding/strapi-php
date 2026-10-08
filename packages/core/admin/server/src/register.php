<?php

declare(strict_types=1);

namespace Strapi\Admin;

use Strapi\Admin\Ai\Services\Ai as AiAdminService;
use Strapi\Admin\Migrations\Database\MigratePreferedLanguageDkToDa;
use Strapi\Admin\Routes\ServeAdminPanel;
use Strapi\Admin\Strategies\Admin as AdminAuthStrategy;
use Strapi\Admin\Strategies\AdminToken as AdminTokenAuthStrategy;
use Strapi\Admin\Strategies\ContentApiToken as ContentApiTokenAuthStrategy;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;

/** Port of server/src/register.ts. */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->db()->migrations->internal->register(MigratePreferedLanguageDkToDa::migration());

        $passportMiddleware = Utils::getService($strapi, 'passport')->init();

        $strapi->server()->api('admin')->use($passportMiddleware);
        $strapi->get('auth')->register('admin', AdminAuthStrategy::strategy($strapi));
        $strapi->get('auth')->register('admin', AdminTokenAuthStrategy::strategy($strapi));
        $strapi->get('auth')->register('content-api', ContentApiTokenAuthStrategy::strategy($strapi));

        $strapi->add('ai.admin', static fn (): AiAdminService => AiAdminService::createAiAdminService($strapi));

        $shouldServeAdminPanel = $strapi->config()->get('admin.serveAdminPanel');

        if ($shouldServeAdminPanel) {
            ServeAdminPanel::registerAdminPanelRoute($strapi);
        }
    }
}
