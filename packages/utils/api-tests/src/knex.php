<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Database\Database;

/**
 * `strapi.db.connection` / `strapi.db.getConnection()` in upstream tests: the knex instance.
 * Called with a table name it is a {@see KnexQuery} on that table (`getConnection(table).where(...)`);
 * a query method starts a query (`connection.select('*').from(table)`); `raw(sql)` runs SQL and
 * `schema` is a {@see KnexSchema}.
 */
final class Knex
{
    public readonly KnexSchema $schema;

    public function __construct(private readonly Database $db)
    {
        $this->schema = new KnexSchema($db);
    }

    /** `knex(table)` */
    public function __invoke(string $table): KnexQuery
    {
        return new KnexQuery($this->db->sql()->from($table));
    }

    /**
     * `knex.raw(sql, bindings)`: a query's rows (better-sqlite3's and mysql2's shape for a SELECT;
     * tests read `result.rows || result` for PostgreSQL's), an empty list for any other statement.
     *
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $connection = $this->db->getConnection();
        if (preg_match('/^\s*(select|with|pragma|show|explain)\b/i', $sql) === 1) {
            return $connection->fetchAllAssociative($sql, $bindings);
        }
        $connection->executeStatement($sql, $bindings);

        return [];
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): KnexQuery
    {
        return (new KnexQuery($this->db->sql()))->{$name}(...$args);
    }
}
