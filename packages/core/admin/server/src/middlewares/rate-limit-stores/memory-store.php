<?php

declare(strict_types=1);

namespace Strapi\Admin\Middlewares\RateLimitStores;

/**
 * koa2-ratelimit's default `MemoryStore`: the hits live in the PHP process, as they live in the
 * Node process upstream. Right for one long-lived process (FrankenPHP worker mode, the CLI); a
 * runtime that starts afresh for each request forgets them ({@see FileStore}).
 */
final class MemoryStore implements Store
{
    /** @var array<string, array{counter: int, dateEnd: int}> */
    private array $hits = [];

    public function incr(string $key, int $intervalMs, int $nowMs): array
    {
        $entry = $this->hits[$key] ?? null;
        if ($entry === null || $entry['dateEnd'] <= $nowMs) {
            $entry = ['counter' => 0, 'dateEnd' => $nowMs + $intervalMs];
        }
        $entry['counter']++;

        return $this->hits[$key] = $entry;
    }

    public function reset(): void
    {
        $this->hits = [];
    }
}
