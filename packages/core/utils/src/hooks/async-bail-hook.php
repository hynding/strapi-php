<?php

declare(strict_types=1);

namespace Strapi\Utils\Hooks;

/** Executes handlers in series and returns the first non-null result. */
final class AsyncBailHook extends Hook
{
    public function call(mixed ...$args): mixed
    {
        $context = $args[0] ?? null;

        foreach ($this->handlers as $handler) {
            $result = $handler($context);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
