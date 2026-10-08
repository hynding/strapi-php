<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Middlewares;

use Strapi\Admin\Middlewares\RateLimit as KoaRateLimit;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\RateLimitError;

/**
 * Port of server/src/middlewares/rateLimit.js. `koa2-ratelimit`'s `RateLimit.middleware` is the
 * admin package's port ({@see KoaRateLimit::middleware()}, in-memory store).
 *
 * Not an upstream file: rateLimit.php must return the middleware factory (the registry
 * `require`s it), so the class with the exported helpers lives here.
 */
final class RateLimit
{
    /**
     * Routes where the rate-limit key MUST NOT include a user identifier
     * derived from `ctx.request.body.email`.
     *
     * On these routes the request body either has no `email` field
     * (e.g. /auth/local uses `identifier`, /auth/reset-password uses
     * `code`, /auth/change-password uses `currentPassword`) or the
     * field is not part of the route contract. Including the
     * attacker-controlled `body.email` in the rate-limit key on these
     * routes lets a caller obtain a fresh key on every request by
     * varying that field, effectively bypassing per-IP throttling.
     *
     * Comparison uses endsWith so the check is stable under any router
     * mount prefix (e.g. `/api/auth/local`).
     *
     * @see https://github.com/strapi/strapi/security/advisories/GHSA-7mqx-wwh4-f9fw
     *
     * When adding a new `rateLimit`-protected auth route whose body does not
     * use `email` as the real identifier, add its path suffix here (or an
     * equivalent `routeUsesEmailIdentifier` rule) so the key cannot be split
     * with arbitrary `body.email` values.
     */
    public const ROUTES_WITHOUT_IDENTIFIER = ['/auth/local', '/auth/reset-password', '/auth/change-password'];

    private static function isOAuthCallbackPath(string $requestPath): bool
    {
        return str_contains($requestPath, '/connect/');
    }

    private static function routeUsesEmailIdentifier(string $requestPath): bool
    {
        if (self::isOAuthCallbackPath($requestPath)) {
            return false;
        }

        foreach (self::ROUTES_WITHOUT_IDENTIFIER as $route) {
            if (str_ends_with($requestPath, $route)) {
                return false;
            }
        }

        return true;
    }

    /** Node's `path.posix.normalize`. */
    private static function posixNormalize(string $path): string
    {
        if ($path === '') {
            return '.';
        }
        $isAbsolute = str_starts_with($path, '/');
        $trailingSeparator = str_ends_with($path, '/');
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$isAbsolute) {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $segment;
        }
        $normalized = implode('/', $out);
        if ($normalized === '' && !$isAbsolute) {
            $normalized = '.';
        }
        if ($normalized !== '' && $trailingSeparator) {
            $normalized .= '/';
        }

        return ($isAbsolute ? '/' : '') . $normalized;
    }

    /**
     * Paths suitable for route matching and prefix keys: POSIX-normalized,
     * lower-cased, trailing slashes removed so `/api/auth/local` and
     * `/api/auth/local/` share one bucket.
     */
    public static function normalizeRequestPathForRateLimit(string $requestPath): string
    {
        $normalized = self::posixNormalize($requestPath);
        $lower = mb_strtolower($normalized);
        $trimmed = (string) preg_replace('~/+$~', '', $lower);

        return $trimmed !== '' ? $trimmed : '/';
    }

    private static function getEmailIdentifierForKey(mixed $body): string
    {
        if (!is_array($body) || !is_string($body['email'] ?? null) || $body['email'] === '') {
            return 'unknownIdentifier';
        }

        return mb_strtolower($body['email']);
    }

    public static function buildPrefixKey(Context $ctx): string
    {
        return self::buildPrefixKeyFromRequest($ctx->path(), $ctx->ip(), $ctx->requestBody());
    }

    /** {@see self::buildPrefixKey()} from `ctx.request.path`, `ctx.request.ip` and `ctx.request.body`. */
    public static function buildPrefixKeyFromRequest(mixed $path, string $ip, mixed $body): string
    {
        if (!is_string($path)) {
            $requestPath = 'invalidPath';
        } else {
            $requestPath = self::normalizeRequestPathForRateLimit($path);
            if ($requestPath === '.' || $requestPath === '..') {
                $requestPath = 'invalidPath';
            }
        }

        if (!self::routeUsesEmailIdentifier($requestPath)) {
            return "noIdentifier:{$requestPath}:{$ip}";
        }

        $userIdentifier = self::getEmailIdentifierForKey($body);

        return "{$userIdentifier}:{$requestPath}:{$ip}";
    }

    /**
     * @param array<string, mixed> $rateLimitConfig
     * @param array<string, mixed> $routeMiddlewareConfig
     * @return array<string, mixed>
     */
    public static function buildRateLimitLoadConfig(Context $ctx, array $rateLimitConfig, array $routeMiddlewareConfig): array
    {
        return self::buildRateLimitLoadConfigWithKey(self::buildPrefixKey($ctx), $rateLimitConfig, $routeMiddlewareConfig);
    }

    /**
     * @param array<string, mixed> $rateLimitConfig
     * @param array<string, mixed> $routeMiddlewareConfig
     * @return array<string, mixed>
     */
    public static function buildRateLimitLoadConfigWithKey(string $prefixKey, array $rateLimitConfig, array $routeMiddlewareConfig): array
    {
        return [
            'interval' => ['min' => 5],
            'max' => 5,
            ...$rateLimitConfig,
            ...$routeMiddlewareConfig,
            'handler' => static function (): never {
                throw new RateLimitError();
            },
            'prefixKey' => $prefixKey,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return \Closure(Context, callable): mixed
     */
    public static function create(array $config, Strapi $strapi): \Closure
    {
        return static function (Context $ctx, callable $next) use ($config, $strapi): mixed {
            $rateLimitConfig = $strapi->config()->get('plugin::users-permissions.ratelimit');

            if (!$rateLimitConfig) {
                $rateLimitConfig = ['enabled' => true];
            }
            $rateLimitConfig = is_array($rateLimitConfig) ? $rateLimitConfig : ['enabled' => true];

            if (!array_key_exists('enabled', $rateLimitConfig)) {
                $rateLimitConfig['enabled'] = true;
            }

            if ($rateLimitConfig['enabled'] === true) {
                $loadConfig = self::buildRateLimitLoadConfig($ctx, $rateLimitConfig, $config);

                return KoaRateLimit::middleware($loadConfig)($ctx, $next);
            }

            return $next();
        };
    }
}
