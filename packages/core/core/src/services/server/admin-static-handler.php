<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Middlewares\PublicStatic;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Not an upstream file (the admin package's `serveAdmin` middleware is not ported yet): serves the
 * built admin bundle under `admin.path`. Looks for `<root>/build` (output of `strapi build`, like
 * upstream's `<distDir>/build`) then `<root>/dist/build`; falls back to a 200 HTML
 * placeholder explaining the bundle is not built. Unknown files resolve to `index.html` (SPA).
 */
final class AdminStaticHandler
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function __invoke(Context $ctx, callable $next): void
    {
        $root = $this->strapi->dirs()->root;
        $adminPath = rtrim((string) $this->strapi->config()->get('admin.path', '/admin'), '/');
        $buildDir = $this->buildDir($root);

        if ($buildDir === null) {
            $ctx->setStatus(200);
            $ctx->setType('text/html; charset=utf-8');
            $ctx->setBody(self::placeholder($adminPath));

            return;
        }

        $relative = (string) ($ctx->param('path') ?? '');
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..')) {
            $relative = 'index.html';
        }

        $file = realpath($buildDir . '/' . $relative);
        if ($file === false || !is_file($file) || !str_starts_with($file, (string) realpath($buildDir))) {
            $file = $buildDir . '/index.html';
        }
        if (!is_file($file)) {
            $ctx->setStatus(200);
            $ctx->setType('text/html; charset=utf-8');
            $ctx->setBody(self::placeholder($adminPath));

            return;
        }

        $ctx->setStatus(200);
        $ctx->setType(PublicStatic::mimeType($file));
        $ctx->setHeader('Cache-Control', basename($file) === 'index.html' ? 'no-cache' : 'public, max-age=31536000');
        $ctx->setBody(\Nyholm\Psr7\Stream::create((string) file_get_contents($file)));
    }

    public static function buildDir(string $root): ?string
    {
        // upstream serves `<distDir>/build` (distDir = appDir in PHP); `.strapi/client` only holds the sources
        foreach ([$root . '/build', $root . '/dist/build'] as $candidate) {
            if (is_file($candidate . '/index.html')) {
                return $candidate;
            }
        }

        return null;
    }

    private static function placeholder(string $adminPath): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Strapi admin panel</title></head>
<body style="font-family: sans-serif; max-width: 40rem; margin: 4rem auto; line-height: 1.5">
<h1>The admin bundle is not built</h1>
<p>This Strapi (PHP) backend serves the upstream <code>@strapi/admin</code> React bundle from
<code>build/</code>. Run <code>bin/strapi build</code> (requires Node.js and the
<code>@strapi/admin</code> npm package pinned to this backend's version) and reload <code>{$adminPath}</code>.</p>
<p>The content API is available under <code>/api</code>.</p>
</body>
</html>
HTML;
    }
}
