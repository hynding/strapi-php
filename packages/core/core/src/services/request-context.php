<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Types\Core\Context;

/**
 * Port of packages/core/core/src/services/request-context.ts. Upstream uses AsyncLocalStorage;
 * PHP handles one request at a time per process, so a stack of contexts is equivalent.
 */
final class RequestContext
{
    /** @var list<Context> */
    private array $stack = [];

    public function run(Context $store, callable $cb): mixed
    {
        $this->stack[] = $store;
        try {
            return $cb();
        } finally {
            array_pop($this->stack);
        }
    }

    public function get(): ?Context
    {
        return $this->stack[array_key_last($this->stack) ?? -1] ?? null;
    }
}
