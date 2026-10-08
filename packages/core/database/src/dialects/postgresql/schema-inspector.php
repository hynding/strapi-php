<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Postgresql;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Strapi\Database\Database;
use Strapi\Database\Dialects\SchemaInspector as SchemaInspectorInterface;

/**
 * Port of packages/core/database/src/dialects/postgresql/schema-inspector.ts.
 *
 * @phpstan-import-type Column from \Strapi\Database\Schema\Types
 * @phpstan-import-type Index from \Strapi\Database\Schema\Types
 * @phpstan-import-type ForeignKey from \Strapi\Database\Schema\Types
 */
final class SchemaInspector implements SchemaInspectorInterface
{
    private const TABLE_LIST = <<<'SQL'
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = ? AND table_type = 'BASE TABLE'
          AND table_name != 'geometry_columns' AND table_name != 'spatial_ref_sys'
        SQL;

    private const BULK_COLUMNS = <<<'SQL'
        SELECT table_name, data_type, column_name, character_maximum_length, column_default, is_nullable
        FROM information_schema.columns
        WHERE table_schema = ? AND table_name IN (?)
        ORDER BY table_name, ordinal_position
        SQL;

    private const BULK_INDEXES = <<<'SQL'
        SELECT t.relname as table_name, ix.indexrelid, i.relname as index_name, a.attname as column_name,
               ix.indisunique as is_unique, ix.indisprimary as is_primary
        FROM pg_class t, pg_namespace s, pg_class i, pg_index ix, pg_attribute a
        WHERE t.oid = ix.indrelid AND i.oid = ix.indexrelid AND a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
          AND t.relkind = 'r' AND t.relnamespace = s.oid AND s.nspname = ? AND t.relname IN (?)
        SQL;

    private const BULK_FOREIGN_KEYS = <<<'SQL'
        SELECT cl.relname AS table_name, c.conname AS constraint_name, att.attname AS column_name,
               fcl.relname AS foreign_table, fatt.attname AS fk_column_name,
               CASE c.confupdtype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_update,
               CASE c.confdeltype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_delete
        FROM pg_constraint c
        JOIN pg_class cl ON cl.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = cl.relnamespace
        JOIN pg_class fcl ON fcl.oid = c.confrelid
        JOIN LATERAL unnest(c.conkey, c.confkey) AS cols(conkey, confkey) ON true
        JOIN pg_attribute att ON att.attrelid = c.conrelid AND att.attnum = cols.conkey
        JOIN pg_attribute fatt ON fatt.attrelid = c.confrelid AND fatt.attnum = cols.confkey
        WHERE c.contype = 'f' AND n.nspname = ? AND cl.relname IN (?) AND fcl.relnamespace = cl.relnamespace
        ORDER BY cl.relname, c.conname, cols.conkey
        SQL;

    public function __construct(private readonly Database $db)
    {
    }

    public function getDatabaseSchema(): string
    {
        return $this->db->getSchemaName() ?: 'public';
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
        $rows = $this->db->connection->fetchAllAssociative(self::TABLE_LIST, [$this->getDatabaseSchema()]);

        return array_map(static fn (array $r): string => (string) $r['table_name'], $rows);
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

    /**
     * @param list<string> $tables
     *
     * @return array<string, list<Column>>
     */
    private function getBulkColumns(array $tables): array
    {
        $rows = $this->db->connection->fetchAllAssociative(self::BULK_COLUMNS, [$this->getDatabaseSchema(), $tables], [ParameterType::STRING, ArrayParameterType::STRING]);
        $result = [];
        foreach ($rows as $row) {
            $strapiType = self::toStrapiType($row);
            $default = $row['column_default'];
            $result[(string) $row['table_name']][] = [
                'type' => $strapiType['type'],
                'args' => $strapiType['args'] ?? [],
                'defaultTo' => is_string($default) && str_contains($default, 'nextval(') ? null : $default,
                'name' => (string) $row['column_name'],
                'notNullable' => $row['is_nullable'] === 'NO',
                'unsigned' => false,
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, list<Index>>
     */
    private function getBulkIndexes(array $tables): array
    {
        $rows = $this->db->connection->fetchAllAssociative(self::BULK_INDEXES, [$this->getDatabaseSchema(), $tables], [ParameterType::STRING, ArrayParameterType::STRING]);
        $byTable = [];
        foreach ($rows as $row) {
            if ($row['column_name'] === 'id') {
                continue;
            }
            $table = (string) $row['table_name'];
            $key = (string) $row['indexrelid'];
            if (isset($byTable[$table][$key])) {
                $byTable[$table][$key]['columns'][] = (string) $row['column_name'];
            } else {
                $type = $row['is_primary'] ? 'primary' : ($row['is_unique'] ? 'unique' : null);
                $index = ['columns' => [(string) $row['column_name']], 'name' => (string) $row['index_name']];
                if ($type !== null) {
                    $index['type'] = $type;
                }
                $byTable[$table][$key] = $index;
            }
        }

        return array_map('array_values', $byTable);
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, list<ForeignKey>>
     */
    private function getBulkForeignKeys(array $tables): array
    {
        $rows = $this->db->connection->fetchAllAssociative(self::BULK_FOREIGN_KEYS, [$this->getDatabaseSchema(), $tables], [ParameterType::STRING, ArrayParameterType::STRING]);
        $byTable = [];
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $name = (string) $row['constraint_name'];
            if (!isset($byTable[$table][$name])) {
                $byTable[$table][$name] = [
                    'name' => $name,
                    'columns' => [(string) $row['column_name']],
                    'referencedColumns' => [(string) $row['fk_column_name']],
                    'referencedTable' => (string) $row['foreign_table'],
                    'onUpdate' => $row['on_update'] !== null ? strtoupper((string) $row['on_update']) : null,
                    'onDelete' => $row['on_delete'] !== null ? strtoupper((string) $row['on_delete']) : null,
                ];
            } else {
                if (!in_array((string) $row['column_name'], $byTable[$table][$name]['columns'], true)) {
                    $byTable[$table][$name]['columns'][] = (string) $row['column_name'];
                }
                if (!in_array((string) $row['fk_column_name'], $byTable[$table][$name]['referencedColumns'], true)) {
                    $byTable[$table][$name]['referencedColumns'][] = (string) $row['fk_column_name'];
                }
            }
        }

        return array_map('array_values', $byTable);
    }

    /**
     * @param array<string, mixed> $column
     *
     * @return array{type: string, args?: list<mixed>}
     */
    public static function toStrapiType(array $column): array
    {
        preg_match('/[^(), ]+/', strtolower((string) $column['data_type']), $m);

        return match ($m[0] ?? '') {
            'integer' => ['type' => 'integer'],
            'text' => ['type' => 'text', 'args' => ['longtext']],
            'boolean' => ['type' => 'boolean'],
            'character' => ['type' => 'string', 'args' => [$column['character_maximum_length']]],
            'timestamp' => ['type' => 'datetime', 'args' => [['useTz' => false, 'precision' => 6]]],
            'date' => ['type' => 'date'],
            'time' => ['type' => 'time', 'args' => [['precision' => 3]]],
            'numeric' => ['type' => 'decimal', 'args' => [10, 2]],
            'real', 'double' => ['type' => 'double'],
            'bigint' => ['type' => 'bigInteger'],
            'jsonb' => ['type' => 'jsonb'],
            default => ['type' => 'specificType', 'args' => [$column['data_type']]],
        };
    }
}
