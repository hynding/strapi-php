<?php

declare(strict_types=1);

namespace Strapi\Database\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Schema\TableDiff;
use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/schema/builder.ts on Doctrine DBAL.
 *
 * Strapi tables/columns/indexes (plain arrays) are translated to DBAL schema objects and the
 * platform generates the DDL. SQLite table rebuilds (ALTER of constraints) come for free from
 * `SQLitePlatform::getAlterTableSQL()`.
 *
 * @phpstan-import-type Schema as SchemaArray from Types
 * @phpstan-import-type Table from Types
 * @phpstan-import-type Column as ColumnArray from Types
 * @phpstan-import-type Index as IndexArray from Types
 * @phpstan-import-type ForeignKey as ForeignKeyArray from Types
 * @phpstan-import-type TableDiff as TableDiffArray from Types
 * @phpstan-import-type SchemaDiff from Types
 */
final class Builder
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Creates schema in DB. @param SchemaArray $schema */
    public function createSchema(array $schema): void
    {
        $this->db->connection->transactional(function (Connection $trx) use ($schema): void {
            $this->createTables($schema['tables'], $trx);
        });
    }

    /** @param list<Table> $tables */
    public function createTables(array $tables, Connection $trx): void
    {
        foreach ($tables as $table) {
            $this->createTable($trx, $table);
        }

        // create FKs once all the tables exist
        foreach ($tables as $table) {
            $this->createTableForeignKeys($trx, $table);
        }
    }

    /** Drops schema from DB. @param SchemaArray $schema */
    public function dropSchema(array $schema, bool $dropDatabase = false): void
    {
        if ($dropDatabase) {
            return;
        }

        $this->db->connection->transactional(function (Connection $trx) use ($schema): void {
            foreach (array_reverse($schema['tables']) as $table) {
                $this->dropTable($trx, $table);
            }
        });
    }

    /**
     * Applies a schema diff update in the DB.
     *
     * @param SchemaDiff['diff'] $schemaDiff
     */
    public function updateSchema(array $schemaDiff): void
    {
        $forceMigration = (bool) ($this->db->config['settings']['forceMigration'] ?? false);

        $this->db->dialect->startSchemaUpdate();

        // Pre-fetch metadata for all updated tables
        $existingMetadata = [];
        foreach ($schemaDiff['tables']['updated'] as $table) {
            $existingMetadata[$table['name']] = [
                'indexes' => $this->db->dialect->schemaInspector->getIndexes($table['name']),
                'foreignKeys' => $this->db->dialect->schemaInspector->getForeignKeys($table['name']),
            ];
        }

        try {
            $this->db->connection->transactional(function (Connection $trx) use ($schemaDiff, $forceMigration, $existingMetadata): void {
                $this->createTables($schemaDiff['tables']['added'], $trx);

                if ($forceMigration) {
                    foreach ($schemaDiff['tables']['removed'] as $table) {
                        $this->dropTableForeignKeys($trx, $table);
                    }

                    foreach ($schemaDiff['tables']['removed'] as $table) {
                        $this->dropTable($trx, $table);
                    }
                }

                foreach ($schemaDiff['tables']['updated'] as $table) {
                    $this->alterTable($trx, $table, $existingMetadata[$table['name']]);
                }
            });
        } finally {
            $this->db->dialect->endSchemaUpdate();
        }
    }

    /** Creates a table in a database. @param Table $table */
    public function createTable(Connection $trx, array $table): void
    {
        $platform = $trx->getDatabasePlatform();
        $dbalTable = $this->toDbalTable($table, $platform, !$this->db->dialect->canAlterConstraints());

        foreach ($platform->getCreateTableSQL($dbalTable) as $sql) {
            $trx->executeStatement($sql);
        }
    }

    /** Creates a table's foreign key constraints. @param Table $table */
    public function createTableForeignKeys(Connection $trx, array $table): void
    {
        if (!$this->db->dialect->canAlterConstraints() || !$this->db->dialect->usesForeignKeys()) {
            return;
        }

        $platform = $trx->getDatabasePlatform();
        foreach ($table['foreignKeys'] ?? [] as $foreignKey) {
            $trx->executeStatement($platform->getCreateForeignKeySQL(self::toDbalForeignKey($foreignKey), $platform->quoteSingleIdentifier($table['name'])));
        }
    }

    /** Drops a table's foreign key constraints. @param Table $table */
    public function dropTableForeignKeys(Connection $trx, array $table): void
    {
        if (!($this->db->config['settings']['forceMigration'] ?? false)) {
            return;
        }
        if (!$this->db->dialect->canAlterConstraints() || !$this->db->dialect->usesForeignKeys()) {
            return;
        }

        $platform = $trx->getDatabasePlatform();
        $existing = array_map(static fn (array $fk): string => $fk['name'], $this->db->dialect->schemaInspector->getForeignKeys($table['name']));
        foreach ($table['foreignKeys'] ?? [] as $foreignKey) {
            if (!in_array($foreignKey['name'], $existing, true)) {
                continue;
            }
            $trx->executeStatement($platform->getDropForeignKeySQL($platform->quoteSingleIdentifier($foreignKey['name']), $platform->quoteSingleIdentifier($table['name'])));
        }
    }

    /** Drops a table from a database. @param Table $table */
    public function dropTable(Connection $trx, array $table): void
    {
        if (!($this->db->config['settings']['forceMigration'] ?? false)) {
            return;
        }

        $platform = $trx->getDatabasePlatform();
        if ($trx->createSchemaManager()->tableExists($table['name'])) {
            $trx->executeStatement($platform->getDropTableSQL($platform->quoteSingleIdentifier($table['name'])));
        }
    }

    /**
     * Alters a table: drop FKs, drop indexes, drop columns, update columns, add columns, recreate
     * FKs and indexes — in that order, like upstream.
     *
     * @param TableDiffArray $table
     * @param array{indexes: list<IndexArray>, foreignKeys: list<ForeignKeyArray>} $existingMetadata
     */
    public function alterTable(Connection $trx, array $table, array $existingMetadata = ['indexes' => [], 'foreignKeys' => []]): void
    {
        $forceMigration = (bool) ($this->db->config['settings']['forceMigration'] ?? false);
        $platform = $trx->getDatabasePlatform();
        $schemaManager = $trx->createSchemaManager();
        $oldTable = $schemaManager->introspectTable($table['name']);

        $existingIndexNames = array_map(static fn (array $i): string => strtolower($i['name']), $existingMetadata['indexes']);
        $existingForeignKeyNames = array_map(static fn (array $fk): string => strtolower($fk['name']), $existingMetadata['foreignKeys']);

        $droppedForeignKeys = [];
        $droppedIndexes = [];
        $droppedColumns = [];
        $changedColumns = [];
        $addedColumns = [];
        $addedForeignKeys = [];
        $addedIndexes = [];

        $usesForeignKeys = $this->db->dialect->usesForeignKeys();

        // Drop foreign keys first to avoid foreign key errors in the following steps
        foreach ([...$table['foreignKeys']['removed'], ...array_column($table['foreignKeys']['updated'], 'object')] as $fk) {
            if ($usesForeignKeys && in_array(strtolower($fk['name']), $existingForeignKeyNames, true) && $oldTable->hasForeignKey($fk['name'])) {
                $droppedForeignKeys[] = $oldTable->getForeignKey($fk['name']);
            }
        }

        // In MySQL, dropping a foreign key can also implicitly drop an index with the same name
        if ($this->db->dialect->client === 'mysql') {
            $droppedFkNames = array_map(static fn (ForeignKeyConstraint $fk): string => strtolower($fk->getName()), $droppedForeignKeys);
            $existingIndexNames = array_values(array_filter($existingIndexNames, static fn (string $n): bool => !in_array($n, $droppedFkNames, true)));
        }

        foreach ([...$table['indexes']['removed'], ...array_column($table['indexes']['updated'], 'object')] as $index) {
            if (!$forceMigration) {
                continue;
            }
            if (in_array(strtolower($index['name']), $existingIndexNames, true) && $oldTable->hasIndex($index['name'])) {
                $droppedIndexes[] = $oldTable->getIndex($index['name']);
            }
        }

        // Drop columns after FKs have been removed to avoid FK errors
        foreach ($table['columns']['removed'] as $column) {
            if ($forceMigration && $oldTable->hasColumn($column['name'])) {
                $droppedColumns[] = $oldTable->getColumn($column['name']);
            }
        }

        // Update existing columns
        foreach ($table['columns']['updated'] as $updatedColumn) {
            $object = $updatedColumn['object'];
            if ($object['type'] === 'increments') {
                $object = [...$object, 'type' => 'integer'];
            }
            if ($oldTable->hasColumn($object['name'])) {
                $changedColumns[] = new ColumnDiff($oldTable->getColumn($object['name']), $this->toDbalColumn($object));
            }
        }

        // Add any new columns
        foreach ($table['columns']['added'] as $addedColumn) {
            if ($addedColumn['type'] === 'increments' && !$this->db->dialect->canAddIncrements()) {
                $addedColumns[] = $this->toDbalColumn([...$addedColumn, 'type' => 'integer']);
                $addedIndexes[] = new Index('primary', [$addedColumn['name']], true, true);
            } else {
                $addedColumns[] = $this->toDbalColumn($addedColumn);
            }
        }

        // once the columns have all been updated, we can create indexes again
        foreach ([...array_column($table['foreignKeys']['updated'], 'object'), ...$table['foreignKeys']['added']] as $fk) {
            if ($usesForeignKeys || !$this->db->dialect->canAlterConstraints()) {
                $addedForeignKeys[] = self::toDbalForeignKey($fk);
            }
        }

        foreach ([...array_column($table['indexes']['updated'], 'object'), ...$table['indexes']['added']] as $index) {
            $addedIndexes[] = self::toDbalIndex($index);
        }

        $diff = new TableDiff(
            $oldTable,
            addedColumns: $addedColumns,
            changedColumns: $changedColumns,
            droppedColumns: $droppedColumns,
            addedIndexes: $addedIndexes,
            droppedIndexes: $droppedIndexes,
            addedForeignKeys: $addedForeignKeys,
            droppedForeignKeys: $droppedForeignKeys,
        );

        foreach ($platform->getAlterTableSQL($diff) as $sql) {
            $trx->executeStatement($sql);
        }
    }

    /**
     * @param Table $table
     */
    public function toDbalTable(array $table, AbstractPlatform $platform, bool $withForeignKeys): DbalTable
    {
        $columns = [];
        $primary = null;
        foreach ($table['columns'] ?? [] as $column) {
            $columns[] = $this->toDbalColumn($column);
            if ($column['type'] === 'increments') {
                $primary = $column['name'];
            }
        }

        $indexes = [];
        foreach ($table['indexes'] ?? [] as $index) {
            if (($index['type'] ?? null) === 'primary') {
                $primary = $index['columns'][0] ?? $primary;
                continue;
            }
            $indexes[] = self::toDbalIndex($index);
        }

        $foreignKeys = [];
        if ($withForeignKeys) {
            foreach ($table['foreignKeys'] ?? [] as $fk) {
                $foreignKeys[] = self::toDbalForeignKey($fk);
            }
        }

        return new DbalTable(
            self::quoteName($table['name']),
            $columns,
            $indexes,
            [],
            $foreignKeys,
            [],
            null,
            $primary !== null ? PrimaryKeyConstraint::editor()->setUnquotedColumnNames($primary)->create() : null,
        );
    }

    /** @param ColumnArray $column */
    public function toDbalColumn(array $column): Column
    {
        [$typeName, $options] = $this->db->dialect->toDbalColumn($column);

        return new Column($column['name'], $typeName, $options);
    }

    /** @param IndexArray $index */
    public static function toDbalIndex(array $index): Index
    {
        $type = strtolower((string) ($index['type'] ?? ''));

        return new Index(self::quoteName($index['name']), $index['columns'], $type === 'unique', $type === 'primary');
    }

    /**
     * knex quotes every identifier; DBAL only quotes the names given quoted. A name that is not a
     * plain identifier (`dogs-collection`, a collectionName the content-type builder accepts) is
     * passed quoted so the generated DDL stays valid.
     */
    public static function quoteName(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : '"' . str_replace('"', '""', $name) . '"';
    }

    /** @param ForeignKeyArray $foreignKey */
    public static function toDbalForeignKey(array $foreignKey): ForeignKeyConstraint
    {
        $options = [];
        if (!empty($foreignKey['onDelete'])) {
            $options['onDelete'] = $foreignKey['onDelete'];
        }
        if (!empty($foreignKey['onUpdate'])) {
            $options['onUpdate'] = $foreignKey['onUpdate'];
        }

        return new ForeignKeyConstraint(
            $foreignKey['columns'],
            self::quoteName($foreignKey['referencedTable']),
            $foreignKey['referencedColumns'],
            self::quoteName($foreignKey['name']),
            $options,
        );
    }
}
