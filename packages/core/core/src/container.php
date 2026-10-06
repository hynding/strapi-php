<?php

declare(strict_types=1);

namespace Strapi\Core;

/**
 * Port of packages/core/core/src/container.ts: a tiny service registry. A resolver is either a
 * value or a `callable(Container $container, mixed $args): mixed`; functions are invoked once and
 * the result memoized (singletons).
 */
class Container
{
    /** @var array<string, mixed> */
    private array $registerMap = [];

    /** @var array<string, mixed> */
    private array $serviceMap = [];

    public function add(string $name, mixed $resolver): static
    {
        if (array_key_exists($name, $this->registerMap)) {
            throw new \RuntimeException("Cannot register already registered service {$name}");
        }

        $this->registerMap[$name] = $resolver;

        return $this;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->registerMap) || array_key_exists($name, $this->serviceMap);
    }

    public function get(string $name, mixed $args = null): mixed
    {
        if (array_key_exists($name, $this->serviceMap)) {
            return $this->serviceMap[$name];
        }

        if (array_key_exists($name, $this->registerMap)) {
            $resolver = $this->registerMap[$name];

            // a Closure (or invokable object) is a factory; plain arrays/scalars/objects are values
            if ($resolver instanceof \Closure) {
                $this->serviceMap[$name] = $resolver($this, $args);
            } else {
                $this->serviceMap[$name] = $resolver;
            }

            return $this->serviceMap[$name];
        }

        throw new \RuntimeException("Could not resolve service {$name}");
    }

    /** Replace a resolved service (used by tests and by `extend`-style overrides). */
    public function set(string $name, mixed $value): static
    {
        $this->serviceMap[$name] = $value;
        $this->registerMap[$name] ??= $value;

        return $this;
    }
}
