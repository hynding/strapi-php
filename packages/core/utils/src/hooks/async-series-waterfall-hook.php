<?php

declare(strict_types=1);

namespace Strapi\Utils\Hooks;

/** Executes every handler in order, passing the return value of the previous one to the next. */
final class AsyncSeriesWaterfallHook extends Hook
{
    public function call(mixed ...$args): mixed
    {
        $res = $args[0] ?? null;

        foreach ($this->handlers as $handler) {
            $res = $handler($res);
        }

        return $res;
    }
}
