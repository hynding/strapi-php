<?php

declare(strict_types=1);

namespace Strapi\Admin\Routes;

use Strapi\Core\Middlewares\PublicStatic;
use Strapi\Core\Services\Server\AdminStaticHandler;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/routes/serve-admin-panel.ts: `GET <admin.path>/:path*` serves the built
 * admin panel. koa-static is replaced by a minimal file server ({@see self::serveStatic()}).
 *
 * The build directory is `<root>/build` (upstream `<distDir>/build`) or `<root>/dist/build`
 * ({@see AdminStaticHandler::buildDir()}); upstream falls back to the package's own `build/`,
 * which the PHP package does not ship, so without a build core's placeholder page is served.
 */
final class ServeAdminPanel
{
    private const ADMIN_SHELL_CACHE_CONTROL = 'no-cache';
    private const ADMIN_SHELL_SURROGATE_CONTROL = 'no-store';
    private const HASHED_ASSET_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** @param callable(string, string): void $setHeader */
    private static function applyAdminShellCacheHeaders(callable $setHeader): void
    {
        $setHeader('Cache-Control', self::ADMIN_SHELL_CACHE_CONTROL);
        $setHeader('Surrogate-Control', self::ADMIN_SHELL_SURROGATE_CONTROL);
    }

    public static function registerAdminPanelRoute(Strapi $strapi): void
    {
        $buildDir = AdminStaticHandler::buildDir($strapi->dirs()->root);

        if ($buildDir === null) {
            // no built bundle: core's handler serves its "not built" page
            $handler = [new AdminStaticHandler($strapi)];
        } else {
            $serveAdminMiddleware = static function (Context $ctx, callable $next) use ($buildDir): void {
                $next();

                if ($ctx->method() !== 'HEAD' && $ctx->method() !== 'GET') {
                    return;
                }

                if ($ctx->body() !== null || $ctx->status() !== 404) {
                    return;
                }

                self::applyAdminShellCacheHeaders(static function (string $name, string $value) use ($ctx): void {
                    $ctx->setHeader($name, $value);
                });
                $ctx->setType('text/html; charset=utf-8');
                $ctx->setBody(\Nyholm\Psr7\Stream::create((string) @file_get_contents($buildDir . '/index.html')));
            };

            $handler = [
                $serveAdminMiddleware,
                self::serveStatic($buildDir, [
                    'maxage' => 0,
                    'defer' => false,
                    'index' => 'index.html',
                    'setHeaders' => static function (Context $ctx, string $path): void {
                        if (pathinfo($path, PATHINFO_EXTENSION) === 'html') {
                            self::applyAdminShellCacheHeaders(static function (string $name, string $value) use ($ctx): void {
                                $ctx->setHeader($name, $value);
                            });

                            return;
                        }

                        $ctx->setHeader('Cache-Control', self::HASHED_ASSET_CACHE_CONTROL);
                    },
                ]),
            ];
        }

        $adminPath = rtrim((string) $strapi->config()->get('admin.path', '/admin'), '/');

        $strapi->server()->routes([
            [
                'method' => 'GET',
                'path' => "{$adminPath}/:path*",
                'handler' => $handler,
                'config' => ['auth' => false],
            ],
        ]);
    }

    /**
     * serveStatic is not supposed to be used to serve a folder that have sub-folders: a request
     * whose path has an extension is served from `<filesDir>/<basename>`.
     *
     * @param array{setHeaders?: callable(Context, string): void} $koaStaticOptions
     * @return \Closure(Context, callable): void
     */
    public static function serveStatic(string $filesDir, array $koaStaticOptions = []): \Closure
    {
        return static function (Context $ctx, callable $next) use ($filesDir, $koaStaticOptions): void {
            $path = $ctx->path();
            if (pathinfo($path, PATHINFO_EXTENSION) === '') {
                $next();

                return;
            }

            $file = rtrim($filesDir, '/') . '/' . basename($path);
            if (!is_file($file) || ($ctx->method() !== 'GET' && $ctx->method() !== 'HEAD')) {
                $next();

                return;
            }

            if (isset($koaStaticOptions['setHeaders'])) {
                ($koaStaticOptions['setHeaders'])($ctx, $file);
            }
            $ctx->setStatus(200);
            $ctx->setType(PublicStatic::mimeType($file));
            $ctx->setBody(\Nyholm\Psr7\Stream::create((string) file_get_contents($file)));
        };
    }
}
