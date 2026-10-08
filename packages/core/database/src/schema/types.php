<?php

declare(strict_types=1);

namespace Strapi\Database\Schema;

/**
 * Port of packages/core/database/src/schema/types.ts: the plain-array shapes schema sync works with.
 *
 * @phpstan-type Column array{type: string, name: string, args?: list<mixed>, defaultTo?: mixed, notNullable?: bool|null, unsigned?: bool, unique?: bool, primary?: bool}
 * @phpstan-type Index array{columns: list<string>, name: string, type?: string|null}
 * @phpstan-type ForeignKey array{name: string, columns: list<string>, referencedColumns: list<string>, referencedTable: string, onUpdate?: string|null, onDelete?: string|null}
 * @phpstan-type Table array{name: string, columns: list<Column>, indexes: list<Index>, foreignKeys: list<ForeignKey>}
 * @phpstan-type SchemaArray array{tables: list<Table>}
 * @phpstan-type ObjectDiff array{name: string, object: array<string, mixed>}
 * @phpstan-type ColumnsDiff array{added: list<Column>, removed: list<Column>, updated: list<array{name: string, object: Column}>, unchanged: list<Column>}
 * @phpstan-type IndexesDiff array{added: list<Index>, removed: list<Index>, updated: list<array{name: string, object: Index}>, unchanged: list<Index>}
 * @phpstan-type ForeignKeysDiff array{added: list<ForeignKey>, removed: list<ForeignKey>, updated: list<array{name: string, object: ForeignKey}>, unchanged: list<ForeignKey>}
 * @phpstan-type TableDiff array{name: string, indexes: IndexesDiff, columns: ColumnsDiff, foreignKeys: ForeignKeysDiff}
 * @phpstan-type TablesDiff array{added: list<Table>, removed: list<Table>, updated: list<TableDiff>, unchanged: list<Table>}
 * @phpstan-type SchemaDiff array{status: 'CHANGED'|'UNCHANGED', diff: array{tables: TablesDiff}}
 */
final class Types
{
    public const CHANGED = 'CHANGED';
    public const UNCHANGED = 'UNCHANGED';
}
