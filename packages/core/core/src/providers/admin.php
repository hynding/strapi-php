<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Loaders\Admin as LoadAdmin;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/providers/admin.ts.
 *
 * STUB: `strapi/admin` is not ported yet. The provider registers an empty `admin` module and, at
 * bootstrap, mounts `/admin` on the server: it serves a built admin bundle when
 * `<root>/.strapi/client` (output of `strapi build`) or `node_modules/@strapi/admin/dist` exists,
 * otherwise a 200 HTML placeholder explaining the bundle is not built.
 */
final class Admin extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        // the admin package will replace this resolver with its strapi-server module
        if (!$strapi->has('admin')) {
            $strapi->add('admin', static fn (): array => []);
        }
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

        if ($strapi->config()->get('admin.serveAdminPanel') === false) {
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
