<?php

declare(strict_types=1);

namespace Strapi\Upload\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Upload\Utils\MimeTypes;

/**
 * Port of server/src/middlewares/upload.ts: the programmatic `GET /uploads/(.*)` route serving the
 * public directory (koa-static, `defer: true`, `providerOptions.localServer` options: `maxage` /
 * `maxAge`) with byte-range support (koa-range). Koa's EPIPE error filter has no PHP counterpart.
 */
final class Upload
{
    public static function registerUploadMiddleware(Strapi $strapi): void
    {
        $localServerConfig = $strapi->config()->get('plugin::upload.providerOptions.localServer', []);
        $localServerConfig = is_array($localServerConfig) ? $localServerConfig : [];
        $maxAge = (int) ($localServerConfig['maxage'] ?? $localServerConfig['maxAge'] ?? 0);
        $publicDir = $strapi->dirs()->public;

        $strapi->server()->routes([
            [
                'method' => 'GET',
                'path' => '/uploads/(.*)',
                'handler' => static function (Context $ctx, callable $next) use ($publicDir, $maxAge): void {
                    // defer: true — let downstream handle first
                    $next();
                    if ($ctx->hasExplicitStatus() || $ctx->body() !== null) {
                        return;
                    }

                    $path = rawurldecode($ctx->path());
                    if (str_contains($path, "\0")) {
                        return;
                    }
                    $real = realpath($publicDir . $path);
                    $root = realpath($publicDir);
                    if ($real === false || $root === false || !is_file($real) || !str_starts_with($real, $root . '/')) {
                        return;
                    }

                    self::send($ctx, $real, $maxAge);
                },
                'config' => ['auth' => false],
            ],
        ]);
    }

    private static function send(Context $ctx, string $file, int $maxAge): void
    {
        $size = (int) filesize($file);
        $type = MimeTypes::lookup($file);

        $ctx->setStatus(200);
        $ctx->setType($type !== false ? $type : 'application/octet-stream');
        $ctx->setHeader('Cache-Control', 'max-age=' . intdiv($maxAge, 1000));
        $ctx->setHeader('Accept-Ranges', 'bytes');
        $mtime = filemtime($file);
        if ($mtime !== false) {
            $ctx->setHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $mtime));
        }

        $range = $ctx->header('range');
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
                $end = $size - 1;
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }

            if ($start > $end || $start >= $size) {
                $ctx->setHeader('Content-Range', "bytes */{$size}");
                $ctx->throw(416);
            }

            $handle = fopen($file, 'rb');
            $content = '';
            if ($handle !== false) {
                fseek($handle, $start);
                $content = (string) fread($handle, max(1, $end - $start + 1));
                fclose($handle);
            }

            $ctx->setStatus(206);
            $ctx->setHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
            $ctx->setBody(\Nyholm\Psr7\Stream::create($content));

            return;
        }

        $ctx->setBody(\Nyholm\Psr7\Stream::create((string) file_get_contents($file)));
    }
}
