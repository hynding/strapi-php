<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Loaders\Admin as LoadAdmin;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/providers/admin.ts.
 *
 * `init` adds the admin module: `strapi.add('admin', () => require('@strapi/admin/strapi-server'))`
 * becomes the `strapi-server.php` of the installed `strapi/admin` Composer package
 * ({@see LoadAdmin::adminServerFile()}). The admin module registers its own panel route.
 *
 * PHP-port fallback when `strapi/admin` is not installed: the module is empty (`[]`) and, at
 * bootstrap, `/admin` is mounted on core's {@see \Strapi\Core\Services\Server\AdminStaticHandler}
 * (the built bundle, or a placeholder page explaining it is not built).
 */
final class Admin extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        if ($strapi->has('admin')) {
            return;
        }

        $serverFile = LoadAdmin::adminServerFile($strapi);
        $strapi->add('admin', $serverFile !== null ? static fn (): mixed => require $serverFile : static fn (): array => []);
    }

    public function register(Strapi $strapi): void
    {
        LoadAdmin::loadAdmin($strapi);

        $admin = $strapi->get('admin');
        if (is_array($admin) && is_callable($admin['register'] ?? null)) {
            $admin['register']($strapi);
        }
    }

    public function bootstrap(Strapi $strapi): void
    {
        $admin = $strapi->get('admin');
        if (is_array($admin) && is_callable($admin['bootstrap'] ?? null)) {
            $admin['bootstrap']($strapi);
        }

        // PHP-port fallback: without the admin package, serve the admin bundle (or a placeholder)
        if (LoadAdmin::hasAdminModule($strapi) || $strapi->config()->get('admin.serveAdminPanel') === false) {
            return;
        }

        $strapi->server()->routes([[
            'method' => 'GET',
            'path' => rtrim((string) $strapi->config()->get('admin.path', '/admin'), '/') . '/:path*',
            'handler' => new \Strapi\Core\Services\Server\AdminStaticHandler($strapi),
            'config' => ['auth' => false],
        ]]);
    }

    public function destroy(Strapi $strapi): void
    {
        $admin = $strapi->get('admin');
        if (is_array($admin) && is_callable($admin['destroy'] ?? null)) {
            $admin['destroy']($strapi);
        }
    }
}
