<?php

declare(strict_types=1);

namespace Strapi\Utils\Hooks;

/** Executes every handler with a deep copy of the context and returns every result as a list. */
final class AsyncParallelHook extends Hook
{
    /** @return list<mixed> */
    public function call(mixed ...$args): array
    {
        $context = $args[0] ?? null;
        $results = [];

        foreach ($this->handlers as $handler) {
            $results[] = $handler(self::cloneDeep($context));
        }

        return $results;
    }

    public static function cloneDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::cloneDeep(...), $value);
        }
        if (is_object($value) && !$value instanceof \Closure) {
            return clone $value;
        }

        return $value;
    }
}
