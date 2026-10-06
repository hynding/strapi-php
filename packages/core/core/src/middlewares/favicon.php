<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/middlewares/favicon.ts (koa-favicon). */
final class Favicon
{
    private const DEFAULTS = ['path' => 'favicon.png', 'maxAge' => 86400000];

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $options = [...self::DEFAULTS, ...$config];
        $maxAge = (int) $options['maxAge'];
        $faviconPathConfig = (string) $options['path'];
        $appRoot = $strapi->dirs()->root;
        $faviconPath = $faviconPathConfig;

        if (!is_file($appRoot . '/' . $faviconPathConfig)) {
            if (is_file($appRoot . '/' . self::DEFAULTS['path'])) {
                $faviconPath = self::DEFAULTS['path'];
            } elseif (is_file($appRoot . '/favicon.ico')) {
                $faviconPath = 'favicon.ico';
            }
        }

        $file = $appRoot . '/' . $faviconPath;
        $maxAgeSeconds = (int) min(max($maxAge, 0), 31556926000) / 1000;

        // koa-favicon
        return static function (Context $ctx, callable $next) use ($file, $maxAgeSeconds): void {
            if ($ctx->path() !== '/favicon.ico') {
                $next();

                return;
            }

            if ($ctx->method() !== 'GET' && $ctx->method() !== 'HEAD') {
                $ctx->setStatus($ctx->method() === 'OPTIONS' ? 200 : 405);
                $ctx->setHeader('Allow', 'GET, HEAD, OPTIONS');

                return;
            }

            if (!is_file($file)) {
                $next();

                return;
            }

            $ctx->setStatus(200);
            $ctx->setHeader('Cache-Control', 'public, max-age=' . (int) $maxAgeSeconds);
            $ctx->setType(PublicStatic::mimeType($file));
            $ctx->setBody(\Nyholm\Psr7\Stream::create((string) file_get_contents($file)));
        };
    }
}
