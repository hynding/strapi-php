<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/middlewares/public.ts (`publicStatic`): `GET /` redirects to the
 * admin URL and every other `GET` not under `/uploads/` is served from the `public/` directory
 * (koa-static with `defer: true`, so routes registered later take precedence). `defaultIndex`
 * (`index.html`) is served for directories.
 */
final class PublicStatic
{
    private const DEFAULTS = ['maxAge' => 60000, 'defaultIndex' => true];

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): ?callable
    {
        $options = [...self::DEFAULTS, ...$config];
        $maxAge = (int) $options['maxAge'];
        $defaultIndex = $options['defaultIndex'];
        $publicDir = $strapi->dirs()->public;

        $strapi->server()->routes([
            [
                'method' => 'GET',
                'path' => '/',
                'handler' => static function (Context $ctx) use ($strapi, $publicDir, $defaultIndex): void {
                    // koa-static with defaultIndex serves public/index.html when present, otherwise redirect to the admin
                    $index = $publicDir . '/index.html';
                    if ($defaultIndex !== false && is_file($index) && !str_contains((string) file_get_contents($index), 'Strapi\\')) {
                        self::serve($ctx, $index, 60000);

                        return;
                    }
                    $ctx->redirect((string) $strapi->config()->get('admin.url', '/admin'));
                },
                'config' => ['auth' => false],
            ],
            // All other public GET-routes except /uploads/(.*) which is handled in upload middleware
            [
                'method' => 'GET',
                'path' => '/((?!uploads/).+)',
                'handler' => static function (Context $ctx, callable $next) use ($publicDir, $maxAge, $defaultIndex): void {
                    // defer: true — let downstream handle first
                    $next();
                    if ($ctx->hasExplicitStatus() || $ctx->body() !== null) {
                        return;
                    }

                    $path = rawurldecode($ctx->path());
                    if (str_contains($path, "\0") || str_contains($path, '..')) {
                        return;
                    }
                    $file = $publicDir . $path;
                    if (is_dir($file) && $defaultIndex !== false) {
                        $file = rtrim($file, '/') . '/' . ($defaultIndex === true ? 'index.html' : (string) $defaultIndex);
                    }
                    $real = realpath($file);
                    if ($real === false || !is_file($real) || !str_starts_with($real, (string) realpath($publicDir))) {
                        return;
                    }
                    if (basename($real) === 'index.php') {
                        return;
                    }

                    self::serve($ctx, $real, $maxAge);
                },
                'config' => ['auth' => false],
            ],
        ]);

        return null;
    }

    public static function serve(Context $ctx, string $file, int $maxAgeMs): void
    {
        $ctx->setStatus(200);
        $ctx->setType(self::mimeType($file));
        $ctx->setHeader('Cache-Control', 'max-age=' . (int) ($maxAgeMs / 1000));
        $mtime = filemtime($file);
        if ($mtime !== false) {
            $ctx->setHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $mtime));
        }
        $ctx->setBody(\Nyholm\Psr7\Stream::create((string) file_get_contents($file)));
    }

    public static function mimeType(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $known = [
            'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'map' => 'application/json', 'pdf' => 'application/pdf',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wasm' => 'application/wasm',
        ];
        if (isset($known[$ext])) {
            return $known[$ext];
        }
        $detected = function_exists('mime_content_type') ? @mime_content_type($file) : false;

        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }
}
