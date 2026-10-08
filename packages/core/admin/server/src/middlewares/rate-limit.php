<?php

declare(strict_types=1);

namespace Strapi\Admin\Middlewares;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\RateLimitError;

/**
 * Port of server/src/middlewares/rateLimit.ts with the part of `koa2-ratelimit` it uses
 * (`RateLimit.middleware` + its default in-memory store). The store lives in the PHP process,
 * like koa2-ratelimit's MemoryStore lives in the Node process.
 *
 * Not an upstream file: rateLimit.php must return the middleware factory (the registry
 * `require`s it), so the class it delegates to lives here.
 */
final class RateLimit
{
    /** @var array<string, array{counter: int, dateEnd: int}> key => hits and end of window (ms) */
    private static array $hits = [];

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

                return self::middleware($loadConfig)($ctx, $next);
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
     * koa2-ratelimit `RateLimit.middleware(options)` with the memory store.
     *
     * @param array<string, mixed> $options
     * @return \Closure(Context, callable): mixed
     */
    public static function middleware(array $options): \Closure
    {
        $interval = self::intervalMs($options['interval'] ?? ['min' => 1]);
        $max = (int) ($options['max'] ?? 5);
        $prefixKey = (string) ($options['prefixKey'] ?? 'global');
        $handler = $options['handler'] ?? null;
        $headers = $options['headers'] ?? true;

        return static function (Context $ctx, callable $next) use ($interval, $max, $prefixKey, $handler, $headers): mixed {
            // keyGenerator: `${prefixKey}|${ctx.request.ip}`
            $key = "{$prefixKey}|{$ctx->ip()}";
            $now = (int) floor(microtime(true) * 1000);

            $entry = self::$hits[$key] ?? null;
            if ($entry === null || $entry['dateEnd'] <= $now) {
                $entry = ['counter' => 0, 'dateEnd' => $now + $interval];
            }
            $entry['counter']++;
            self::$hits[$key] = $entry;

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
        self::$hits = [];
    }
}
