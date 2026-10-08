<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib;

use Strapi\Types\Core\Context;

/**
 * `@koa/cors` 5.0 (index.js): the CORS middleware the plugin puts in front of the GraphQL
 * endpoint. Options: `origin` (string or `callable(Context): string|false`), `exposeHeaders`,
 * `allowMethods`, `allowHeaders`, `maxAge`, `credentials` (bool or callable), `keepHeadersOnError`,
 * `secureContext`, `privateNetworkAccess`.
 */
final class KoaCors
{
    /**
     * @param array<string, mixed> $options
     * @return \Closure(Context, callable): mixed
     */
    public static function create(array $options = []): \Closure
    {
        $options = [
            'allowMethods' => 'GET,HEAD,PUT,POST,DELETE,PATCH',
            'secureContext' => false,
            ...$options,
        ];

        $join = static fn (mixed $value): mixed => is_array($value) ? implode(',', array_map('strval', $value)) : $value;

        $exposeHeaders = $join($options['exposeHeaders'] ?? null);
        $allowMethods = $join($options['allowMethods']);
        $allowHeaders = $join($options['allowHeaders'] ?? null);
        $maxAge = isset($options['maxAge']) && $options['maxAge'] !== false && $options['maxAge'] !== 0 && $options['maxAge'] !== '' ? (string) $options['maxAge'] : null;
        $keepHeadersOnError = !array_key_exists('keepHeadersOnError', $options) || $options['keepHeadersOnError'] === null || (bool) $options['keepHeadersOnError'];
        $secureContext = (bool) $options['secureContext'];
        $privateNetworkAccess = (bool) ($options['privateNetworkAccess'] ?? false);
        $originOption = $options['origin'] ?? null;
        $credentialsOption = $options['credentials'] ?? false;

        return static function (Context $ctx, callable $next) use ($exposeHeaders, $allowMethods, $allowHeaders, $maxAge, $keepHeadersOnError, $secureContext, $privateNetworkAccess, $originOption, $credentialsOption): mixed {
            // If the Origin header is not present terminate this set of steps.
            // The request is outside the scope of this specification.
            $requestOrigin = $ctx->header('Origin') ?? '';

            // Always set Vary header
            $vary = $ctx->responseHeaders()['vary'] ?? $ctx->responseHeaders()['Vary'] ?? [];
            $varyValues = array_map('trim', explode(',', implode(',', is_array($vary) ? $vary : [$vary])));
            if (!in_array('Origin', $varyValues, true) && !in_array('*', $varyValues, true)) {
                $ctx->appendHeader('Vary', 'Origin');
            }

            if (is_callable($originOption) && !is_string($originOption)) {
                $origin = $originOption($ctx);
                if (!$origin) {
                    return $next();
                }
            } else {
                $origin = is_string($originOption) && $originOption !== '' ? $originOption : '*';
            }

            $credentials = is_callable($credentialsOption) && !is_string($credentialsOption) ? $credentialsOption($ctx) : (bool) $credentialsOption;

            if ($credentials && $origin === '*') {
                $origin = $requestOrigin;
            }
            $origin = (string) $origin;

            $headersSet = [];
            $set = static function (string $key, string $value) use ($ctx, &$headersSet): void {
                $ctx->setHeader($key, $value);
                $headersSet[$key] = $value;
            };

            if ($ctx->method() !== 'OPTIONS') {
                // Simple Cross-Origin Request, Actual Request, and Redirects
                $set('Access-Control-Allow-Origin', $origin);

                if ($credentials === true) {
                    $set('Access-Control-Allow-Credentials', 'true');
                }

                if (is_string($exposeHeaders) && $exposeHeaders !== '') {
                    $set('Access-Control-Expose-Headers', $exposeHeaders);
                }

                if ($secureContext) {
                    $set('Cross-Origin-Opener-Policy', 'same-origin');
                    $set('Cross-Origin-Embedder-Policy', 'require-corp');
                }

                if (!$keepHeadersOnError) {
                    return $next();
                }

                try {
                    return $next();
                } catch (\Throwable $err) {
                    // the errors middleware (outside) formats the response: keep the CORS headers
                    foreach ($headersSet as $key => $value) {
                        $ctx->setHeader($key, $value);
                    }
                    throw $err;
                }
            }

            // Preflight Request

            // If there is no Access-Control-Request-Method header or if parsing failed,
            // do not set any additional headers and terminate this set of steps.
            // The request is outside the scope of this specification.
            if (($ctx->header('Access-Control-Request-Method') ?? '') === '') {
                // this not preflight request, ignore it
                return $next();
            }

            $ctx->setHeader('Access-Control-Allow-Origin', $origin);

            if ($credentials === true) {
                $ctx->setHeader('Access-Control-Allow-Credentials', 'true');
            }

            if ($maxAge !== null) {
                $ctx->setHeader('Access-Control-Max-Age', $maxAge);
            }

            if ($privateNetworkAccess && ($ctx->header('Access-Control-Request-Private-Network') ?? '') !== '') {
                $ctx->setHeader('Access-Control-Allow-Private-Network', 'true');
            }

            if (is_string($allowMethods) && $allowMethods !== '') {
                $ctx->setHeader('Access-Control-Allow-Methods', $allowMethods);
            }

            if ($secureContext) {
                $set('Cross-Origin-Opener-Policy', 'same-origin');
                $set('Cross-Origin-Embedder-Policy', 'require-corp');
            }

            $headers = is_string($allowHeaders) && $allowHeaders !== '' ? $allowHeaders : ($ctx->header('Access-Control-Request-Headers') ?? '');
            if ($headers !== '') {
                $ctx->setHeader('Access-Control-Allow-Headers', $headers);
            }

            $ctx->setStatus(204);

            return null;
        };
    }
}
