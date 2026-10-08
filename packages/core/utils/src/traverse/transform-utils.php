<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

/**
 * `{ set, recurse }` handed to attribute handlers inside a Factory traversal.
 *
 * @phpstan-import-type Visitor from Factory
 * @phpstan-import-type TraverseOptions from Factory
 */
final class TransformUtils
{
    /** @var \Closure(string, mixed): void */
    private readonly \Closure $setFn;

    /** @var \Closure(Visitor, TraverseOptions, mixed): mixed */
    private readonly \Closure $recurseFn;

    /**
     * @param callable(string, mixed): void $set
     * @param callable(Visitor, TraverseOptions, mixed): mixed $recurse
     */
    public function __construct(callable $set, callable $recurse)
    {
        $this->setFn = $set(...);
        $this->recurseFn = $recurse(...);
    }

    public function set(string $key, mixed $value): void
    {
        ($this->setFn)($key, $value);
    }

    /**
     * @param Visitor $visitor
     * @param TraverseOptions $options
     */
    public function recurse(callable $visitor, array $options, mixed $data): mixed
    {
        return ($this->recurseFn)($visitor, $options, $data);
    }
}
