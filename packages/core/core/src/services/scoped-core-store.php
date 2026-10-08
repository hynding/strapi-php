<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Types\Modules\CoreStore\CoreStore as CoreStoreContract;

/**
 * Upstream's `strapi.store({ type, name })` returns a store bound to default params; this is
 * that object (part of services/core-store.ts, split out for the one-class-per-file rule).
 */
final class ScopedCoreStore implements CoreStoreContract
{
    /** @param array{key?: string, value?: mixed, type?: string, environment?: string|null, name?: string|null, tag?: string|null} $defaultParams */
    public function __construct(private readonly CoreStore $store, private readonly array $defaultParams)
    {
    }

    public function get(array $params): mixed
    {
        return $this->store->get([...$this->defaultParams, ...$params]);
    }

    public function set(array $params): void
    {
        $this->store->set([...$this->defaultParams, ...$params]);
    }

    public function delete(array $params): void
    {
        $this->store->delete([...$this->defaultParams, ...$params]);
    }
}
