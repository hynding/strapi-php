<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests;

/**
 * A recording stand-in for upstream's `jest.fn()` service mocks: each method returns its
 * configured value (or the result of its configured closure) and every call is recorded.
 * `__invoke` is configured under the name `invoke`.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class Mock
{
    /** @var array<string, list<list<mixed>>> */
    public array $calls = [];

    /** @param array<string, mixed> $methods name => return value or \Closure */
    public function __construct(private array $methods = [])
    {
    }

    public function set(string $name, mixed $value): self
    {
        $this->methods[$name] = $value;

        return $this;
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        if (!array_key_exists($name, $this->methods)) {
            throw new \BadMethodCallException("Mock: unexpected call to {$name}()");
        }
        $this->calls[$name][] = $args;
        $value = $this->methods[$name];

        return $value instanceof \Closure ? $value(...$args) : $value;
    }

    public function __invoke(mixed ...$args): mixed
    {
        return $this->__call('invoke', array_values($args));
    }

    public function called(string $name): int
    {
        return count($this->calls[$name] ?? []);
    }
}
