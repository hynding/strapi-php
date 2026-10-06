<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/middlewares/compression.ts (koa-compress): gzip/deflate the
 * response when the client accepts it and the body exceeds `threshold` (default 1024 bytes).
 * Applied in {@see \Strapi\Core\Services\Server\Server::handle()} output stage through the context body.
 */
final class Compression
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $threshold = Body::bytes($config['threshold'] ?? 1024);

        return static function (Context $ctx, callable $next) use ($threshold): void {
            $next();

            $accept = strtolower($ctx->get('Accept-Encoding'));
            $encoding = str_contains($accept, 'gzip') ? 'gzip' : (str_contains($accept, 'deflate') ? 'deflate' : null);
            if ($encoding === null || $ctx->method() === 'HEAD') {
                return;
            }

            $body = $ctx->body();
            if ($body === null || $body instanceof \Psr\Http\Message\StreamInterface) {
                return;
            }
            $raw = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($raw) < $threshold || $ctx->responseHeader('Content-Encoding') !== null) {
                return;
            }

            $compressed = $encoding === 'gzip' ? gzencode($raw) : gzdeflate($raw);
            if ($compressed === false) {
                return;
            }

            if (!is_string($body) && $ctx->type() === null) {
                $ctx->setType('application/json; charset=utf-8');
            }
            $ctx->setHeader('Content-Encoding', $encoding);
            $ctx->appendHeader('Vary', 'Accept-Encoding');
            $ctx->removeHeader('Content-Length');
            $ctx->setBody(\Nyholm\Psr7\Stream::create($compressed));
        };
    }
}
