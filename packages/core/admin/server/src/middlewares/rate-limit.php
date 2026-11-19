<?php

declare(strict_types=1);

namespace Strapi\Admin\Middlewares;

use Strapi\Admin\Middlewares\RateLimitStores\FileStore;
use Strapi\Admin\Middlewares\RateLimitStores\MemoryStore;
use Strapi\Admin\Middlewares\RateLimitStores\Store;
use Strapi\Core\Strapi;
use Strapi\Core\Utils\Sapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\RateLimitError;

/**
 * Port of server/src/middlewares/rateLimit.ts with the part of `koa2-ratelimit` it uses
 * (`RateLimit.middleware` + its default in-memory store). The store lives in the PHP process,
 * like koa2-ratelimit's MemoryStore lives in the Node process, except where the PHP process
 * starts afresh for each request (PHP-FPM, CGI, mod_php, the built-in server): there it would
 * never limit, and the hits are kept in files instead ({@see self::defaultStore()}).
 *
 * Not an upstream file: rateLimit.php must return the middleware factory (the registry
 * `require`s it), so the class it delegates to lives here.
 */
final class RateLimit
{
    private static ?MemoryStore $memory = null;

    /**
     * koa2-ratelimit's default store is in memory, for the Node process's lifetime. In PHP that is
     * the process for the CLI and FrankenPHP worker mode, but one request for the SAPIs that start
     * afresh, where memory would never limit: those count in `.tmp/rate-limit` of the project,
     * or, when it cannot be written, in memory with a warning (the limits are not enforced then,
     * but the admin can still log in).
     */
    public static function defaultStore(?Strapi $strapi, string $sapi = PHP_SAPI): Store
    {
        if ($strapi !== null && Sapi::isPerRequest($sapi)) {
            $dir = $strapi->dirs()->root . '/.tmp/rate-limit';
            if (self::isWritableDirectory($dir)) {
                return new FileStore($dir);
            }
            $strapi->log()->warning("Rate limits are not enforced: {$dir} cannot be written");
        }

        return self::$memory ??= new MemoryStore();
    }

    private static function isWritableDirectory(string $dir): bool
    {
        return (is_dir($dir) || @mkdir($dir, 0o777, true) || is_dir($dir)) && is_writable($dir);
    }

    /**
     * @param array<string, mixed> $config
     * @return \Closure(Context, callable): mixed
     */
    public static function create(array $config, Strapi $strapi): \Closure
    {
        return static function (Context $ctx, callable $next) use ($config, $strapi): mixed {
            $rateLimitConfig = $strapi->config()->get('admin.rateLimit');

            if (!$rateLimitConfig) {
                $rateLimitConfig = ['enabled' => true];
            }
            $rateLimitConfig = is_array($rateLimitConfig) ? $rateLimitConfig : ['enabled' => true];

            if (!array_key_exists('enabled', $rateLimitConfig)) {
                $rateLimitConfig['enabled'] = true;
            }

            if ($rateLimitConfig['enabled'] === true) {
                $body = $ctx->requestBody();
                $requestEmail = is_array($body) ? ($body['email'] ?? null) : null;
                $userEmail = is_string($requestEmail) ? strtolower($requestEmail) : 'unknownEmail';

                $requestPath = rtrim(strtolower(self::normalizePath($ctx->path())), '/');
                // a trailing-slash-only path normalizes to '' like `'/'.replace(/\/$/, '')`
                $requestPath = $ctx->path() === '' ? 'invalidPath' : $requestPath;

                $loadConfig = [
                    'interval' => ['min' => 5],
                    'max' => 5,
                    'prefixKey' => "{$userEmail}:{$requestPath}:{$ctx->ip()}",
                    'handler' => static function (): never {
                        throw new RateLimitError();
                    },
                    ...$rateLimitConfig,
                    ...$config,
                ];

                return self::middleware($loadConfig, $strapi)($ctx, $next);
            }

            return $next();
        };
    }

    /** Node `path.normalize` for URL paths: resolves `.`/`..` and collapses slashes. */
    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '.';
        }
        $isAbsolute = str_starts_with($path, '/');
        $trailing = str_ends_with($path, '/');
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
        if ($normalized !== '' && $trailing) {
            $normalized .= '/';
        }

        return ($isAbsolute ? '/' : '') . $normalized;
    }

    /** koa2-ratelimit `interval`: milliseconds, or `{ ms, sec, min, hour, day, week, month, year }`. */
    private static function intervalMs(mixed $interval): int
    {
        if (is_int($interval) || is_float($interval)) {
            return (int) $interval;
        }
        if (!is_array($interval)) {
            return 60_000;
        }
        $units = ['ms' => 1, 'sec' => 1000, 'min' => 60_000, 'hour' => 3_600_000, 'day' => 86_400_000, 'week' => 604_800_000, 'month' => 2_592_000_000, 'year' => 31_536_000_000];
        $ms = 0;
        foreach ($units as $unit => $factor) {
            if (isset($interval[$unit]) && is_numeric($interval[$unit])) {
                $ms += (int) ($interval[$unit] * $factor);
            }
        }

        return $ms;
    }

    /**
     * koa2-ratelimit `RateLimit.middleware(options)`. Its `store` option takes a {@see Store}; by
     * default {@see self::defaultStore()} for `$strapi`.
     *
     * @param array<string, mixed> $options
     * @return \Closure(Context, callable): mixed
     */
    public static function middleware(array $options, ?Strapi $strapi = null): \Closure
    {
        $interval = self::intervalMs($options['interval'] ?? ['min' => 1]);
        $max = (int) ($options['max'] ?? 5);
        $prefixKey = (string) ($options['prefixKey'] ?? 'global');
        $handler = $options['handler'] ?? null;
        $headers = $options['headers'] ?? true;
        $store = ($options['store'] ?? null) instanceof Store ? $options['store'] : self::defaultStore($strapi);

        return static function (Context $ctx, callable $next) use ($interval, $max, $prefixKey, $handler, $headers, $store): mixed {
            // keyGenerator: `${prefixKey}|${ctx.request.ip}`
            $key = "{$prefixKey}|{$ctx->ip()}";
            $now = (int) floor(microtime(true) * 1000);

            $entry = $store->incr($key, $interval, $now);

            if ($headers) {
                $ctx->setHeader('X-RateLimit-Limit', (string) $max);
                $ctx->setHeader('X-RateLimit-Remaining', (string) max(0, $max - $entry['counter']));
                $ctx->setHeader('X-RateLimit-Reset', (string) (int) ceil($entry['dateEnd'] / 1000));
            }

            if ($max > 0 && $entry['counter'] > $max) {
                $ctx->setHeader('Retry-After', (string) (int) ceil(($entry['dateEnd'] - $now) / 1000));
                if (is_callable($handler)) {
                    return $handler($ctx, $next);
                }
                $ctx->setStatus(429);
                $ctx->setBody('Too many requests, please try again later.');

                return null;
            }

            return $next();
        };
    }

    /** Clears the in-memory store (tests). */
    public static function reset(): void
    {
        self::$memory?->reset();
    }
}
