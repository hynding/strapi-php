<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects;

/**
 * Upstream `SchemaInspector` interface: reads the live database into the Strapi schema shape
 * (`{ tables: [{ name, columns, indexes, foreignKeys }] }`).
 *
 * @phpstan-import-type Schema from \Strapi\Database\Schema\Types
 * @phpstan-import-type Index from \Strapi\Database\Schema\Types
 * @phpstan-import-type ForeignKey from \Strapi\Database\Schema\Types
 * @phpstan-import-type Column from \Strapi\Database\Schema\Types
 */
interface SchemaInspector
{
    /** @return Schema */
    public function getSchema(): array;

    /** @return list<string> */
    public function getTables(): array;

    /** @return list<Column> */
    public function getColumns(string $tableName): array;

    /** @return list<Index> */
    public function getIndexes(string $tableName): array;

    /** @return list<ForeignKey> */
    public function getForeignKeys(string $tableName): array;
}
