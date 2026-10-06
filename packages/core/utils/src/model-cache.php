<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Types\Schema\Schema;

/**
 * Port of packages/core/utils/src/model-cache.ts: memoizes `getModel(uid)` for one traversal/request.
 */
final class ModelCache
{
    /** @var array<string, Schema|array<string, mixed>|null> */
    private array $cache = [];

    /** @var \Closure(string): (Schema|array<string, mixed>|null) */
    private readonly \Closure $getModelFn;

    /** @param callable(string): (Schema|array<string, mixed>|null) $getModelFn */
    public function __construct(callable $getModelFn)
    {
        $this->getModelFn = $getModelFn(...);
    }

    /** @param callable(string): (Schema|array<string, mixed>|null) $getModelFn */
    public static function createModelCache(callable $getModelFn): self
    {
        return new self($getModelFn);
    }

    /** @return Schema|array<string, mixed>|null */
    public function getModel(string $uid): Schema|array|null
    {
        if (array_key_exists($uid, $this->cache) && $this->cache[$uid] !== null) {
            return $this->cache[$uid];
        }

        $model = ($this->getModelFn)($uid);
        $this->cache[$uid] = $model;

        return $model;
    }

    /** The cached lookup as a callable usable wherever a `getModel` is expected. */
    public function callable(): \Closure
    {
        return $this->getModel(...);
    }

    public function clear(): void
    {
        $this->cache = [];
    }
}
