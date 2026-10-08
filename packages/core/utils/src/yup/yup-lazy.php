<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/**
 * `yup.lazy((value, options) => schema)`: the schema is built from the value at validation time.
 * As in yup, a lazy schema only resolves; modifiers belong on the schema the builder returns.
 */
final class YupLazy extends Yup
{
    protected string $type = 'lazy';

    /** @var \Closure(mixed, array<string, mixed>): mixed */
    private \Closure $builder;

    /** @param callable(mixed, array<string, mixed>): mixed $builder */
    public function __construct(callable $builder)
    {
        parent::__construct();
        $this->builder = $builder(...);
    }

    public function resolve(array $options = []): Yup
    {
        $schema = ($this->builder)(array_key_exists('value', $options) ? $options['value'] : Undefined::value(), $options);
        if (!$schema instanceof Yup) {
            throw new \TypeError('lazy() functions must return a valid schema');
        }

        return $schema->resolve($options);
    }

    public function describe(): array
    {
        return [];
    }
}
