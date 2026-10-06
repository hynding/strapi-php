<?php

declare(strict_types=1);

namespace Strapi\Utils\Policy;

/** The `{ name, validator, handler }` object returned by `createPolicy`. */
final class PolicyDefinition
{
    /** @var \Closure(mixed): void */
    public readonly \Closure $validator;

    /** @var \Closure */
    public readonly \Closure $handler;

    /**
     * @param callable(mixed): void $validator
     */
    public function __construct(public readonly string $name, callable $validator, callable $handler)
    {
        $this->validator = $validator(...);
        $this->handler = $handler(...);
    }

    public function validate(mixed $config): void
    {
        ($this->validator)($config);
    }

    public function handle(mixed ...$args): mixed
    {
        return ($this->handler)(...$args);
    }
}
