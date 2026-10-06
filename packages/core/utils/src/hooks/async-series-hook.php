<?php

declare(strict_types=1);

namespace Strapi\Utils\Hooks;

/** Executes every handler in order with the same context. */
final class AsyncSeriesHook extends Hook
{
    public function call(mixed ...$args): mixed
    {
        $context = $args[0] ?? null;

        foreach ($this->handlers as $handler) {
            $handler($context);
        }

        return null;
    }
}
