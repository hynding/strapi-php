<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/middlewares/cors.ts together with the `@koa/cors` logic it
 * delegates to (no third-party package). Options: `origin` (string, comma-separated string, list or
 * `callable(Context): string|list<string>`), `expose`, `maxAge`, `credentials`, `methods`, `headers`,
 * `keepHeadersOnError`, with upstream's defaults.
 */
final class Cors
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'origin' => '*',
        'maxAge' => 31536000,
        'credentials' => true,
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
        'headers' => ['Content-Type', 'Authorization', 'Origin', 'Accept'],
        'keepHeadersOnError' => false,
    ];

    /**
     * Determines if a request origin is allowed based on the configured origin list.
     *
     * @param string|list<string>|callable|null $configuredOrigin
     * @return string the allowed origin or '' when blocked
     */
    public static function matchOrigin(?string $requestOrigin, mixed $configuredOrigin, ?Context $ctx = null): string
    {
        if ($requestOrigin === null || $requestOrigin === '') {
            return '*';
        }

        $originList = is_callable($configuredOrigin) && !is_string($configuredOrigin) ? $configuredOrigin($ctx) : $configuredOrigin;

        if (is_array($originList)) {
            $normalizedOrigins = array_values(array_map('strval', $originList));
        } elseif ($originList === null) {
            // Handle undefined/null - treat as wildcard
            $normalizedOrigins = ['*'];
        } else {
            // Handle comma-separated string of origins
            $normalizedOrigins = array_map('trim', explode(',', (string) $originList));
        }

        if (in_array('*', $normalizedOrigins, true)) {
            return $requestOrigin;
        }

        return in_array($requestOrigin, $normalizedOrigins, true) ? $requestOrigin : '';
    }

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $options = [...self::DEFAULTS, ...$config];
        $origin = $options['origin'];
        $expose = $options['expose'] ?? null;
        $maxAge = $options['maxAge'];
        $credentials = $options['credentials'];
        $methods = $options['methods'];
        $headers = $options['headers'];
        $keepHeadersOnError = (bool) $options['keepHeadersOnError'];

        if (array_key_exists('enabled', $config)) {
            $strapi->log()->warning(
                'The strapi::cors middleware no longer supports the `enabled` option. Using it' .
                ' to conditionally enable CORS might cause an insecure default. To disable strapi::cors, remove it from' .
                ' the exported array in config/middleware.js',
            );
        }

        $join = static fn (mixed $v): string => is_array($v) ? implode(',', $v) : (string) $v;

        // @koa/cors
        return static function (Context $ctx, callable $next) use ($origin, $expose, $maxAge, $credentials, $methods, $headers, $keepHeadersOnError, $join): void {
            // If the Origin header is not present terminate this set of steps.
            $requestOrigin = $ctx->get('Origin');

            // Always set Vary header
            $ctx->appendHeader('Vary', 'Origin');

            if ($requestOrigin === '') {
                $next();

                return;
            }

            $allowedOrigin = self::matchOrigin($requestOrigin, $origin, $ctx);
            if ($allowedOrigin === '') {
                $next();

                return;
            }

            $allowCredentials = $credentials === true;

            $headersSet = [];
            $set = static function (string $key, string $value) use ($ctx, &$headersSet): void {
                $ctx->setHeader($key, $value);
                $headersSet[$key] = $value;
            };

            if ($ctx->method() !== 'OPTIONS') {
                // Simple Cross-Origin Request, Actual Request, and Redirects
                $set('Access-Control-Allow-Origin', $allowedOrigin);

                if ($allowCredentials) {
                    $set('Access-Control-Allow-Credentials', 'true');
                }

                if ($expose !== null && $expose !== '' && $expose !== []) {
                    $set('Access-Control-Expose-Headers', $join($expose));
                }

                if (!$keepHeadersOnError) {
                    $next();

                    return;
                }

                try {
                    $next();
                } catch (\Throwable $err) {
                    // keep the CORS headers on errors: the errors middleware (outside) formats the response
                    foreach ($headersSet as $key => $value) {
                        $ctx->setHeader($key, $value);
                    }
                    throw $err;
                }

                return;
            }

            // Preflight Request

            // If there is no Access-Control-Request-Method header or if parsing failed,
            // do not set any additional headers and terminate this set of steps.
            if ($ctx->get('Access-Control-Request-Method') === '') {
                // this not preflight request, ignore it
                $next();

                return;
            }

            $ctx->setHeader('Access-Control-Allow-Origin', $allowedOrigin);

            if ($allowCredentials) {
                $ctx->setHeader('Access-Control-Allow-Credentials', 'true');
            }

            if ($maxAge !== null && $maxAge !== '') {
                $ctx->setHeader('Access-Control-Max-Age', (string) $maxAge);
            }

            if ($methods !== null && $methods !== '' && $methods !== []) {
                $ctx->setHeader('Access-Control-Allow-Methods', $join($methods));
            }

            $allowHeaders = $headers;
            if ($allowHeaders === null || $allowHeaders === '' || $allowHeaders === []) {
                $allowHeaders = $ctx->get('Access-Control-Request-Headers');
            }
            if ($allowHeaders !== '' && $allowHeaders !== null && $allowHeaders !== []) {
                $ctx->setHeader('Access-Control-Allow-Headers', $join($allowHeaders));
            }

            $ctx->setStatus(204);
        };
    }
}
