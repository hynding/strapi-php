<?php

declare(strict_types=1);

namespace Strapi\Utils\Hooks;

/**
 * Base hook from hooks.ts `createHook`: a registry of handlers whose `call` is not implemented.
 */
class Hook
{
    /** @var list<callable> */
    protected array $handlers = [];

    /** @return list<callable> */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function register(callable $handler): static
    {
        $this->handlers[] = $handler;

        return $this;
    }

    public function delete(callable $handler): static
    {
        $this->handlers = array_values(array_filter(
            $this->handlers,
            static fn (callable $registered): bool => !self::sameCallable($registered, $handler),
        ));

        return $this;
    }

    public function call(mixed ...$args): mixed
    {
        throw new \LogicException('Method not implemented');
    }

    private static function sameCallable(callable $a, callable $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \Closure || $b instanceof \Closure) {
            return false;
        }

        // string and [object, method] callables compare by value
        return is_array($a) && is_array($b) ? $a === $b : (is_string($a) && is_string($b) && $a === $b);
    }
}
