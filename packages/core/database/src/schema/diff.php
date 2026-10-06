<?php

declare(strict_types=1);

namespace Strapi\Database\Schema;

use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/schema/diff.ts: a 3-way diff between the previously stored
 * schema, the live database schema and the schema built from metadata.
 *
 * @phpstan-import-type Schema as SchemaArray from Types
 * @phpstan-import-type Table from Types
 * @phpstan-import-type Column from Types
 * @phpstan-import-type Index from Types
 * @phpstan-import-type ForeignKey from Types
 * @phpstan-import-type SchemaDiff from Types
 * @phpstan-import-type TableDiff from Types
 * @phpstan-import-type ColumnsDiff from Types
 * @phpstan-import-type IndexesDiff from Types
 * @phpstan-import-type ForeignKeysDiff from Types
 */
final class Diff
{
    public const RESERVED_TABLE_NAMES = [
        'strapi_migrations',
        'strapi_migrations_internal',
        'strapi_database_schema',
    ];

    /** @var callable(): list<string|array{name: string, dependsOn?: list<array{name: string}>}> */
    private $persistedTablesProvider;

    public function __construct(private readonly Database $db)
    {
        $this->persistedTablesProvider = static fn (): array => [];
    }

    /**
     * Upstream reads `strapi.store.get({ type: 'core', key: 'persisted_tables' })`; core injects the
     * equivalent reader here.
     *
     * @param callable(): list<string|array{name: string, dependsOn?: list<array{name: string}>}> $provider
     */
    public function setPersistedTablesProvider(callable $provider): void
    {
        $this->persistedTablesProvider = $provider;
    }

    /**
     * @param array{previousSchema?: SchemaArray|null, databaseSchema: SchemaArray, userSchema: SchemaArray} $ctx
     *
     * @return SchemaDiff
     */
    public function diff(array $ctx): array
    {
        $previousSchema = $ctx['previousSchema'] ?? null;
        $databaseSchema = $ctx['databaseSchema'];
        $userSchema = $ctx['userSchema'];

        $addedTables = [];
        $updatedTables = [];
        $unchangedTables = [];
        $removedTables = [];

        foreach ($userSchema['tables'] as $userSchemaTable) {
            $databaseTable = self::findTable($databaseSchema, $userSchemaTable['name']);
            $previousTable = $previousSchema !== null ? self::findTable($previousSchema, $userSchemaTable['name']) : null;

            if ($databaseTable !== null) {
                ['status' => $status, 'diff' => $diff] = $this->diffTables($previousTable, $databaseTable, $userSchemaTable);

                if ($status === Types::CHANGED) {
                    $updatedTables[] = $diff;
                } else {
                    $unchangedTables[] = $databaseTable;
                }
            } else {
                $addedTables[] = $userSchemaTable;
            }
        }

        $persistedTables = self::hasTable($databaseSchema, 'strapi_core_store_settings')
            ? (($this->persistedTablesProvider)() ?: [])
            : [];

        $reservedTables = [
            ...self::RESERVED_TABLE_NAMES,
            ...array_map(static fn (string|array $t): string => is_string($t) ? $t : $t['name'], $persistedTables),
        ];

        foreach ($databaseSchema['tables'] as $databaseTable) {
            $isInUserSchema = self::hasTable($userSchema, $databaseTable['name']);
            $wasTracked = $previousSchema !== null && self::hasTable($previousSchema, $databaseTable['name']);
            $isReserved = in_array($databaseTable['name'], $reservedTables, true);

            // a db table not in the user schema and not tracked before is a user custom table: leave it alone
            if (!$isInUserSchema && !$wasTracked) {
                continue;
            }

            if (!$isInUserSchema && $wasTracked && !$isReserved) {
                $dependencies = [];
                foreach ($persistedTables as $persisted) {
                    $dependsOn = is_array($persisted) ? ($persisted['dependsOn'] ?? null) : null;
                    if (!is_array($dependsOn)) {
                        continue;
                    }
                    foreach ($dependsOn as $dep) {
                        if (($dep['name'] ?? null) === $databaseTable['name']) {
                            $found = self::findTable($databaseSchema, $persisted['name']);
                            if ($found !== null) {
                                $dependencies[] = $found;
                            }
                            break;
                        }
                    }
                }

                $removedTables[] = $databaseTable;
                array_push($removedTables, ...$dependencies);
            }
        }

        $hasChanged = $addedTables !== [] || $updatedTables !== [] || $removedTables !== [];

        return [
            'status' => $hasChanged ? Types::CHANGED : Types::UNCHANGED,
            'diff' => [
                'tables' => [
                    'added' => $addedTables,
                    'updated' => $updatedTables,
                    'unchanged' => $unchangedTables,
                    'removed' => $removedTables,
                ],
            ],
        ];
    }

    /**
     * @param Table|null $previousTable
     * @param Table $databaseTable
     * @param Table $userSchemaTable
     *
     * @return array{status: string, diff: TableDiff}
     */
    public function diffTables(?array $previousTable, array $databaseTable, array $userSchemaTable): array
    {
        $columnsDiff = $this->diffTableColumns($previousTable, $databaseTable, $userSchemaTable);
        $indexesDiff = $this->diffTableIndexes($previousTable, $databaseTable, $userSchemaTable);
        $foreignKeysDiff = $this->diffTableForeignKeys($previousTable, $databaseTable, $userSchemaTable);

        $hasChanged = $columnsDiff['status'] === Types::CHANGED
            || $indexesDiff['status'] === Types::CHANGED
            || $foreignKeysDiff['status'] === Types::CHANGED;

        return [
            'status' => $hasChanged ? Types::CHANGED : Types::UNCHANGED,
            'diff' => [
                'name' => $databaseTable['name'],
                'indexes' => $indexesDiff['diff'],
                'foreignKeys' => $foreignKeysDiff['diff'],
                'columns' => $columnsDiff['diff'],
            ],
        ];
    }

    /** @param Index $oldIndex  @param Index $index  @return array{status: string, diff: array{name: string, object: Index}} */
    public function diffIndexes(array $oldIndex, array $index): array
    {
        $changes = [];

        // use xor to avoid differences in order
        if (array_diff($index['columns'], $oldIndex['columns']) !== [] || array_diff($oldIndex['columns'], $index['columns']) !== []) {
            $changes[] = 'columns';
        }

        if (!empty($oldIndex['type']) && !empty($index['type']) && strtolower($oldIndex['type']) !== strtolower($index['type'])) {
            $changes[] = 'type';
        }

        return [
            'status' => $changes !== [] ? Types::CHANGED : Types::UNCHANGED,
            'diff' => ['name' => $index['name'], 'object' => $index],
        ];
    }

    /** @param ForeignKey $oldForeignKey  @param ForeignKey $foreignKey  @return array{status: string, diff: array{name: string, object: ForeignKey}} */
    public function diffForeignKeys(array $oldForeignKey, array $foreignKey): array
    {
        $changes = [];

        if (array_diff($oldForeignKey['columns'], $foreignKey['columns']) !== []) {
            $changes[] = 'columns';
        }

        if (array_diff($oldForeignKey['referencedColumns'], $foreignKey['referencedColumns']) !== []) {
            $changes[] = 'referencedColumns';
        }

        if ($oldForeignKey['referencedTable'] !== $foreignKey['referencedTable']) {
            $changes[] = 'referencedTable';
        }

        foreach (['onDelete', 'onUpdate'] as $action) {
            $old = $oldForeignKey[$action] ?? null;
            $new = $foreignKey[$action] ?? null;
            if ($old === null || strtoupper($old) === 'NO ACTION') {
                if ($new !== null && strtoupper($old ?? '') !== 'NO ACTION') {
                    $changes[] = $action;
                }
            } elseif (strtoupper($old) !== strtoupper($new ?? '')) {
                $changes[] = $action;
            }
        }

        return [
            'status' => $changes !== [] ? Types::CHANGED : Types::UNCHANGED,
            'diff' => ['name' => $foreignKey['name'], 'object' => $foreignKey],
        ];
    }

    /** @param Column $oldColumn  @param Column $column */
    public function diffDefault(array $oldColumn, array $column): bool
    {
        $oldDefaultTo = $oldColumn['defaultTo'] ?? null;
        $defaultTo = $column['defaultTo'] ?? null;

        $lower = static fn (mixed $v): string => strtolower(is_scalar($v) ? (string) $v : (is_array($v) ? json_encode($v) : ''));

        if ($oldDefaultTo === null || $lower($oldDefaultTo) === 'null') {
            return $defaultTo === null || $lower($defaultTo) === 'null';
        }

        return $lower($oldDefaultTo) === $lower($defaultTo)
            || $lower($oldDefaultTo) === $lower("'" . (is_scalar($defaultTo) ? (string) $defaultTo : '') . "'");
    }

    /** @param Column $oldColumn  @param Column $column  @return array{status: string, diff: array{name: string, object: Column}} */
    public function diffColumns(array $oldColumn, array $column): array
    {
        $changes = [];

        $isIgnoredType = in_array($column['type'], ['increments'], true);
        $oldType = $oldColumn['type'];
        $type = $this->db->dialect->getSqlType($column['type']);

        if ($oldType !== $type && !$isIgnoredType) {
            $changes[] = 'type';
        }

        if (($oldColumn['notNullable'] ?? null) !== ($column['notNullable'] ?? null)) {
            $changes[] = 'notNullable';
        }

        if (!$this->diffDefault($oldColumn, $column)) {
            $changes[] = 'defaultTo';
        }

        if (($oldColumn['unsigned'] ?? false) !== ($column['unsigned'] ?? false) && $this->db->dialect->supportsUnsigned()) {
            $changes[] = 'unsigned';
        }

        return [
            'status' => $changes !== [] ? Types::CHANGED : Types::UNCHANGED,
            'diff' => ['name' => $column['name'], 'object' => $column],
        ];
    }

    /** @param Table|null $previousTable  @param Table $databaseTable  @param Table $userSchemaTable  @return array{status: string, diff: ColumnsDiff} */
    public function diffTableColumns(?array $previousTable, array $databaseTable, array $userSchemaTable): array
    {
        $added = [];
        $updated = [];
        $unchanged = [];
        $removed = [];

        foreach ($userSchemaTable['columns'] as $userSchemaColumn) {
            $databaseColumn = self::findByName($databaseTable['columns'], $userSchemaColumn['name']);

            if ($databaseColumn !== null) {
                ['status' => $status, 'diff' => $diff] = $this->diffColumns($databaseColumn, $userSchemaColumn);
                if ($status === Types::CHANGED) {
                    $updated[] = $diff;
                } else {
                    $unchanged[] = $databaseColumn;
                }
            } else {
                $added[] = $userSchemaColumn;
            }
        }

        foreach ($databaseTable['columns'] as $databaseColumn) {
            if (
                self::findByName($userSchemaTable['columns'], $databaseColumn['name']) === null
                && $previousTable !== null
                && self::findByName($previousTable['columns'], $databaseColumn['name']) !== null
            ) {
                $removed[] = $databaseColumn;
            }
        }

        return [
            'status' => ($added !== [] || $updated !== [] || $removed !== []) ? Types::CHANGED : Types::UNCHANGED,
            'diff' => ['added' => $added, 'updated' => $updated, 'unchanged' => $unchanged, 'removed' => $removed],
        ];
    }

    /** @param Table|null $previousTable  @param Table $databaseTable  @param Table $userSchemaTable  @return array{status: string, diff: IndexesDiff} */
    public function diffTableIndexes(?array $previousTable, array $databaseTable, array $userSchemaTable): array
    {
        $added = [];
        $updated = [];
        $unchanged = [];
        $removed = [];

        foreach ($userSchemaTable['indexes'] as $userSchemaIndex) {
            $databaseIndex = self::findByName($databaseTable['indexes'], $userSchemaIndex['name']);
            if ($databaseIndex !== null) {
                ['status' => $status, 'diff' => $diff] = $this->diffIndexes($databaseIndex, $userSchemaIndex);
                if ($status === Types::CHANGED) {
                    $updated[] = $diff;
                } else {
                    $unchanged[] = $databaseIndex;
                }
            } else {
                $added[] = $userSchemaIndex;
            }
        }

        foreach ($databaseTable['indexes'] as $databaseIndex) {
            if (
                self::findByName($userSchemaTable['indexes'], $databaseIndex['name']) === null
                && $previousTable !== null
                && self::findByName($previousTable['indexes'], $databaseIndex['name']) !== null
            ) {
                $removed[] = $databaseIndex;
            }
        }

        return [
            'status' => ($added !== [] || $updated !== [] || $removed !== []) ? Types::CHANGED : Types::UNCHANGED,
            'diff' => ['added' => $added, 'updated' => $updated, 'unchanged' => $unchanged, 'removed' => $removed],
        ];
    }

    /** @param Table|null $previousTable  @param Table $databaseTable  @param Table $userSchemaTable  @return array{status: string, diff: ForeignKeysDiff} */
    public function diffTableForeignKeys(?array $previousTable, array $databaseTable, array $userSchemaTable): array
    {
        $added = [];
        $updated = [];
        $unchanged = [];
        $removed = [];

        if (!$this->db->dialect->usesForeignKeys()) {
            return [
                'status' => Types::UNCHANGED,
                'diff' => ['added' => [], 'updated' => [], 'unchanged' => [], 'removed' => []],
            ];
        }

        foreach ($userSchemaTable['foreignKeys'] as $userSchemaForeignKey) {
            $databaseForeignKey = self::findByName($databaseTable['foreignKeys'], $userSchemaForeignKey['name']);
            if ($databaseForeignKey !== null) {
                ['status' => $status, 'diff' => $diff] = $this->diffForeignKeys($databaseForeignKey, $userSchemaForeignKey);
                if ($status === Types::CHANGED) {
                    $updated[] = $diff;
                } else {
                    $unchanged[] = $databaseForeignKey;
                }
            } else {
                $added[] = $userSchemaForeignKey;
            }
        }

        foreach ($databaseTable['foreignKeys'] as $databaseForeignKey) {
            if (
                self::findByName($userSchemaTable['foreignKeys'], $databaseForeignKey['name']) === null
                && $previousTable !== null
                && self::findByName($previousTable['foreignKeys'], $databaseForeignKey['name']) !== null
            ) {
                $removed[] = $databaseForeignKey;
            }
        }

        return [
            'status' => ($added !== [] || $updated !== [] || $removed !== []) ? Types::CHANGED : Types::UNCHANGED,
            'diff' => ['added' => $added, 'updated' => $updated, 'unchanged' => $unchanged, 'removed' => $removed],
        ];
    }

    /** @param SchemaArray $schema */
    public static function hasTable(array $schema, string $tableName): bool
    {
        return self::findTable($schema, $tableName) !== null;
    }

    /** @param SchemaArray $schema  @return Table|null */
    public static function findTable(array $schema, string $tableName): ?array
    {
        foreach ($schema['tables'] as $table) {
            if ($table['name'] === $tableName) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private static function findByName(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }

        return null;
    }
}
