<?php

declare(strict_types=1);

namespace Strapi\Database\Query;

/** A raw SQL fragment with positional `?` bindings (Knex `knex.raw`). */
final class Raw
{
    /** @param list<mixed> $bindings */
    public function __construct(public readonly string $sql, public readonly array $bindings = [])
    {
    }
}
