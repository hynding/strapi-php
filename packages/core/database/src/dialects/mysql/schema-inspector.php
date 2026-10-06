<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Mysql;

use Doctrine\DBAL\ArrayParameterType;
use Strapi\Database\Database;
use Strapi\Database\Dialects\SchemaInspector as SchemaInspectorInterface;

/** Port of packages/core/database/src/dialects/mysql/schema-inspector.ts. */
final class SchemaInspector implements SchemaInspectorInterface
{
    private const TABLE_LIST = "SELECT t.table_name as table_name FROM information_schema.tables t WHERE table_type = 'BASE TABLE' AND table_schema = schema()";

    private const BULK_COLUMNS = <<<'SQL'
        SELECT c.table_name as table_name, c.data_type as data_type, c.column_name as column_name,
               c.character_maximum_length as character_maximum_length, c.column_default as column_default,
               c.is_nullable as is_nullable, c.column_type as column_type, c.column_key as column_key
        FROM information_schema.columns c
        WHERE table_schema = database() AND table_name in (?)
        ORDER BY c.table_name, c.ordinal_position
        SQL;

    private const BULK_INDEXES = <<<'SQL'
        SELECT s.table_name as table_name, s.index_name as key_name, s.column_name as column_name, s.non_unique as non_unique
        FROM information_schema.statistics s
        WHERE s.table_schema = database() AND s.table_name in (?)
        ORDER BY s.table_name, s.index_name, s.seq_in_index
        SQL;

    private const BULK_FOREIGN_KEYS = <<<'SQL'
        SELECT tc.table_name as table_name, tc.constraint_name as constraint_name, kcu.column_name as column_name,
               kcu.referenced_table_name as referenced_table_name, kcu.referenced_column_name as referenced_column_name,
               rc.update_rule as on_update, rc.delete_rule as on_delete
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu
          ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema AND tc.table_name = kcu.table_name
        JOIN information_schema.referential_constraints rc
          ON tc.constraint_name = rc.constraint_name AND tc.table_schema = rc.constraint_schema AND tc.table_name = rc.table_name
        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = database() AND tc.table_name in (?)
        ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position
        SQL;

    public function __construct(private readonly Database $db)
    {
    }

    public function getSchema(): array
    {
        $tables = $this->getTables();
        if ($tables === []) {
            return ['tables' => []];
        }

        $columns = $this->getBulkColumns($tables);
        $indexes = $this->getBulkIndexes($tables);
        $foreignKeys = $this->getBulkForeignKeys($tables);

        return ['tables' => array_map(static fn (string $t): array => [
            'name' => $t,
            'columns' => $columns[$t] ?? [],
            'indexes' => $indexes[$t] ?? [],
            'foreignKeys' => $foreignKeys[$t] ?? [],
        ], $tables)];
    }

    public function getTables(): array
    {
        return array_map(static fn (array $r): string => (string) $r['table_name'], $this->db->connection->fetchAllAssociative(self::TABLE_LIST));
    }

    public function getColumns(string $tableName): array
    {
        return $this->getBulkColumns([$tableName])[$tableName] ?? [];
    }

    public function getIndexes(string $tableName): array
    {
        return $this->getBulkIndexes([$tableName])[$tableName] ?? [];
    }

    public function getForeignKeys(string $tableName): array
    {
        return $this->getBulkForeignKeys([$tableName])[$tableName] ?? [];
    }

    /** @param list<string> $tables  @return array<string, list<array<string, mixed>>> */
    private function getBulkColumns(array $tables): array
    {
        $rows = $this->db->connection->fetchAllAssociative(self::BULK_COLUMNS, [$tables], [ArrayParameterType::STRING]);
        $result = [];
        foreach ($rows as $row) {
            $strapiType = self::toStrapiType($row);
            $result[(string) $row['table_name']][] = [
                'type' => $strapiType['type'],
                'args' => $strapiType['args'] ?? [],
                'defaultTo' => $row['column_default'],
                'name' => (string) $row['column_name'],
                'notNullable' => $row['is_nullable'] === 'NO',
                'unsigned' => str_ends_with((string) $row['column_type'], ' unsigned'),
                ...(isset($strapiType['unsigned']) ? ['unsigned' => $strapiType['unsigned']] : []),
            ];
        }

        return $result;
    }

    /** @param list<string> $tables  @return array<string, list<array<string, mixed>>> */
    private function getBulkIndexes(array $tables): array
    {
        $rows = $this->db->connection->fetchAllAssociative(self::BULK_INDEXES, [$tables], [ArrayParameterType::STRING]);
        $byTable = [];
        foreach ($rows as $row) {
            if ($row['column_name'] === 'id') {
                continue;
            }
            $table = (string) $row['table_name'];
            $name = (string) $row['key_name'];
            if (isset($byTable[$table][$name])) {
                $byTable[$table][$name]['columns'][] = (string) $row['column_name'];
            } else {
                $index = ['columns' => [(string) $row['column_name']], 'name' => $name];
                if (!$row['non_unique'] || (string) $row['non_unique'] === '0') {
                    $index['type'] = 'unique';
                }
                $byTable[$table][$name] = $index;
            }
        }

        return array_map('array_values', $byTable);
    }

    /** @param list<string> $tables  @return array<string, list<array<string, mixed>>> */
    private function getBulkForeignKeys(array $tables): array
    {
        $rows = $this->db->connection->fetchAllAssociative(self::BULK_FOREIGN_KEYS, [$tables], [ArrayParameterType::STRING]);
        $byTable = [];
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $name = (string) $row['constraint_name'];
            if (!isset($byTable[$table][$name])) {
                $byTable[$table][$name] = [
                    'name' => $name,
                    'columns' => [(string) $row['column_name']],
                    'referencedColumns' => $row['referenced_column_name'] !== null ? [(string) $row['referenced_column_name']] : [],
                    'referencedTable' => $row['referenced_table_name'],
                    'onUpdate' => $row['on_update'] !== null ? strtoupper((string) $row['on_update']) : null,
                    'onDelete' => $row['on_delete'] !== null ? strtoupper((string) $row['on_delete']) : null,
                ];
            } else {
                if (!in_array((string) $row['column_name'], $byTable[$table][$name]['columns'], true)) {
                    $byTable[$table][$name]['columns'][] = (string) $row['column_name'];
                }
                if ($row['referenced_column_name'] !== null && !in_array((string) $row['referenced_column_name'], $byTable[$table][$name]['referencedColumns'], true)) {
                    $byTable[$table][$name]['referencedColumns'][] = (string) $row['referenced_column_name'];
                }
            }
        }

        return array_map('array_values', $byTable);
    }

    /** @param array<string, mixed> $column  @return array{type: string, args?: list<mixed>, unsigned?: bool} */
    public static function toStrapiType(array $column): array
    {
        preg_match('/[^(), ]+/', strtolower((string) $column['data_type']), $m);

        return match ($m[0] ?? '') {
            'int' => ($column['column_key'] ?? null) === 'PRI'
                ? ['type' => 'increments', 'args' => [['primary' => true, 'primaryKey' => true]], 'unsigned' => false]
                : ['type' => 'integer'],
            'decimal' => ['type' => 'decimal', 'args' => [10, 2]],
            'double' => ['type' => 'double'],
            'bigint' => ['type' => 'bigInteger'],
            'enum' => ['type' => 'string'],
            'tinyint' => ['type' => 'boolean'],
            'longtext' => ['type' => 'text', 'args' => ['longtext']],
            'varchar' => ['type' => 'string', 'args' => [$column['character_maximum_length']]],
            'datetime' => ['type' => 'datetime', 'args' => [['useTz' => false, 'precision' => 6]]],
            'date' => ['type' => 'date'],
            'time' => ['type' => 'time', 'args' => [['precision' => 3]]],
            'timestamp' => ['type' => 'timestamp', 'args' => [['useTz' => false, 'precision' => 6]]],
            'json' => ['type' => 'jsonb'],
            default => ['type' => 'specificType', 'args' => [$column['data_type']]],
        };
    }
}
