<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

/** `{ set, recurse }` handed to attribute handlers inside a Factory traversal. */
final class TransformUtils
{
    /** @var \Closure(string, mixed): void */
    private readonly \Closure $setFn;

    /** @var \Closure(callable, array<string, mixed>, mixed): mixed */
    private readonly \Closure $recurseFn;

    /**
     * @param callable(string, mixed): void $set
     * @param callable(callable, array<string, mixed>, mixed): mixed $recurse
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
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: \Strapi\Types\Schema\Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     */
    public function recurse(callable $visitor, array $options, mixed $data): mixed
    {
        return ($this->recurseFn)($visitor, $options, $data);
    }
}
