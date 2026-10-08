<?php

declare(strict_types=1);

namespace Strapi\Database\Utils;

use Strapi\Database\Database;
use Strapi\Database\Query\Raw;
use Strapi\Database\Query\SqlBuilder;

/**
 * Port of packages/core/database/src/utils/knex.ts. Knex's QueryBuilder and Raw are this port's
 * `SqlBuilder` (the Knex-like layer over DBAL) and `Raw`.
 */
final class Knex
{
    /**
     * @internal
     * @phpstan-assert-if-true SqlBuilder|Raw $value
     */
    public static function isKnexQuery(mixed $value): bool
    {
        return $value instanceof SqlBuilder || $value instanceof Raw;
    }

    /**
     * Adds the name of the schema to the table name if the schema was defined by the user.
     * Users can set the db schema only for Postgres in strapi database config.
     */
    public static function addSchema(Database $db, string $tableName): string
    {
        $schemaName = $db->getSchemaName();

        return $schemaName !== null && $schemaName !== '' ? "{$schemaName}.{$tableName}" : $tableName;
    }
}
