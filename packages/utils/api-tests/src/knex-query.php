<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Database\Query\SqlBuilder;

/**
 * `strapi.db.connection(table)` in upstream tests: a knex query builder on `table` (see {@see Knex}).
 * Wraps the database's knex-like {@see SqlBuilder} with the knex spellings it lacks
 * (`select('a', 'b')`, `first()`, `del()`, `transacting(trx)`); awaiting the chain runs it (see
 * {@see Bridge::handle()}).
 */
final class KnexQuery
{
    private bool $first = false;

    public function __construct(private SqlBuilder $builder)
    {
    }

    /** @param string|list<string> ...$columns `select('a', 'b')` or `select(['a', 'b'])` */
    public function select(string|array ...$columns): self
    {
        $flat = array_values(array_merge(...array_map(static fn (string|array $c): array => (array) $c, $columns)));
        $this->builder = $this->builder->select($flat === [] ? '*' : $flat);

        return $this;
    }

    /** The worker has one connection, the transaction is the current one: nothing to switch to. */
    public function transacting(mixed $trx = null): self
    {
        return $this;
    }

    public function first(string ...$columns): self
    {
        if ($columns !== []) {
            $this->builder = $this->builder->select(array_values($columns));
        }
        $this->builder = $this->builder->limit(1);
        $this->first = true;

        return $this;
    }

    public function del(): self
    {
        $this->builder = $this->builder->delete();

        return $this;
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): self
    {
        $result = $this->builder->{$name}(...$args);
        if ($result instanceof SqlBuilder) {
            $this->builder = $result;
        }

        return $this;
    }

    /** @return list<array<string, mixed>>|array<string, mixed>|int|null */
    public function run(): array|int|null
    {
        $result = $this->builder->run();

        if ($this->first) {
            return is_array($result) ? ($result[0] ?? null) : null;
        }

        return $result;
    }
}
