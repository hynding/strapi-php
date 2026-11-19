<?php

declare(strict_types=1);

namespace Strapi\Admin\Middlewares\RateLimitStores;

/**
 * koa2-ratelimit's `Store` (the `store` option of `RateLimit.middleware`): counts the hits of a
 * key within a fixed window. Not an upstream file: koa2-ratelimit is an npm dependency, ported
 * with {@see \Strapi\Admin\Middlewares\RateLimit}.
 */
interface Store
{
    /**
     * Counts one hit of `$key`; a window of `$intervalMs` starts at the first hit after the last
     * window ended.
     *
     * @return array{counter: int, dateEnd: int} the hits in the current window and its end (ms)
     */
    public function incr(string $key, int $intervalMs, int $nowMs): array;
}
