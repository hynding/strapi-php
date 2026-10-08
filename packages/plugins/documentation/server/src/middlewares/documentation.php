<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Middlewares;

use Strapi\Core\Middlewares\PublicStatic;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/middlewares/documentation.ts: serves the Swagger UI assets under
 * `/plugins/documentation/*` (koa-static with `maxage: 86400000, defer: true`).
 *
 * The assets come from the `swagger-ui-dist` npm package, as upstream
 * (`require('swagger-ui-dist').getAbsoluteFSPath()`): it is looked up in `node_modules` from the
 * project root upwards (install it next to the admin bundle: `npm install swagger-ui-dist@4.19.0`).
 */
final class Documentation
{
    public const SWAGGER_UI_DIST_VERSION = '4.19.0';

    public static function addDocumentMiddlewares(Strapi $strapi): void
    {
        $strapi->server()->routes([
            [
                'method' => 'GET',
                'path' => '/plugins/documentation/(.*)',
                'handler' => static function (Context $ctx, callable $next) use ($strapi): void {
                    // ctx.url = path.basename(ctx.url): koa-static serves the file's basename
                    $file = basename(rawurldecode($ctx->path()));

                    // defer: true — let downstream handle first
                    $next();
                    if ($ctx->body() !== null || $ctx->status() !== 404) {
                        return;
                    }
                    if (!in_array($ctx->method(), ['GET', 'HEAD'], true) || $file === '' || str_starts_with($file, '.')) {
                        return;
                    }

                    $root = self::getAbsoluteFSPath($strapi);
                    if ($root === null) {
                        $strapi->log()->warning('[documentation] swagger-ui-dist is not installed: run `npm install swagger-ui-dist@' . self::SWAGGER_UI_DIST_VERSION . '` in the project to serve the Swagger UI assets');

                        return;
                    }

                    $path = $root . '/' . $file;
                    if (!is_file($path)) {
                        return;
                    }

                    self::send($ctx, $path, 86400000);
                },
                'config' => [
                    'auth' => false,
                ],
            ],
        ]);
    }

    /**
     * koa-send: serves `$file` (`Cache-Control: max-age=<seconds>`, `Last-Modified`, the type by
     * extension).
     */
    public static function send(Context $ctx, string $file, int $maxAgeMs): void
    {
        $ctx->setStatus(200);
        $ctx->setType(PublicStatic::mimeType($file));
        $ctx->setHeader('Cache-Control', 'max-age=' . intdiv($maxAgeMs, 1000));
        $mtime = filemtime($file);
        if ($mtime !== false) {
            $ctx->setHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $mtime));
        }
        $ctx->setBody(\Nyholm\Psr7\Stream::create((string) file_get_contents($file)));
    }

    /**
     * `require('swagger-ui-dist').getAbsoluteFSPath()`: `node_modules/swagger-ui-dist` from the
     * project root (then this package) upwards, or null when not installed.
     *
     * @param Strapi $strapi
     */
    public static function getAbsoluteFSPath(object $strapi): ?string
    {
        static $cache = [];

        $root = $strapi->dirs()->root;
        if (array_key_exists($root, $cache)) {
            return $cache[$root];
        }

        foreach ([$root, dirname(__DIR__, 3)] as $start) {
            $dir = $start;
            while (true) {
                $candidate = $dir . '/node_modules/swagger-ui-dist';
                if (is_file($candidate . '/swagger-ui-bundle.js')) {
                    return $cache[$root] = (string) realpath($candidate);
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        return $cache[$root] = null;
    }
}
