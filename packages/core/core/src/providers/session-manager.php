<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\SessionManager as SessionManagerService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/session-manager.ts. */
final class SessionManager extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('sessionManager', static fn (): SessionManagerService => SessionManagerService::createSessionManager(['db' => $strapi->db()]));
    }

    public function bootstrap(Strapi $strapi): void
    {
        if ($strapi->config()->get('admin.serveAdminPanel') === false) {
            return;
        }

        $jwtSecret = $strapi->config()->get('admin.auth.secret');

        if (empty($jwtSecret)) {
            throw new \RuntimeException('Missing admin.auth.secret configuration. The SessionManager requires a JWT secret');
        }
    }
}
