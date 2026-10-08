<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Sqlite;

use Strapi\Database\Database;
use Strapi\Database\Dialects\SchemaInspector as SchemaInspectorInterface;

/**
 * Port of packages/core/database/src/dialects/sqlite/schema-inspector.ts.
 *
 * @phpstan-import-type SchemaArray from \Strapi\Database\Schema\Types
 * @phpstan-import-type Column from \Strapi\Database\Schema\Types
 * @phpstan-import-type Index from \Strapi\Database\Schema\Types
 * @phpstan-import-type ForeignKey from \Strapi\Database\Schema\Types
 */
final class SchemaInspector implements SchemaInspectorInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function getSchema(): array
    {
        $schema = ['tables' => []];

        foreach ($this->getTables() as $tableName) {
            $schema['tables'][] = [
                'name' => $tableName,
                'columns' => $this->getColumns($tableName),
                'indexes' => $this->getIndexes($tableName),
                'foreignKeys' => $this->getForeignKeys($tableName),
            ];
        }

        return $schema;
    }

    public function getTables(): array
    {
        $rows = $this->db->connection->fetchAllAssociative("select name from sqlite_master where type = 'table' and name NOT LIKE 'sqlite%'");

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function getColumns(string $tableName): array
    {
        $rows = $this->db->connection->fetchAllAssociative('pragma table_info(' . $this->quote($tableName) . ')');

        $columns = [];
        foreach ($rows as $row) {
            $strapiType = self::toStrapiType($row);
            $columns[] = [
                'type' => $strapiType['type'],
                'args' => $strapiType['args'] ?? [],
                'name' => (string) $row['name'],
                'defaultTo' => $row['dflt_value'],
                'notNullable' => $row['notnull'] !== null ? (bool) $row['notnull'] : null,
                'unsigned' => false,
            ];
        }

        return $columns;
    }

    public function getIndexes(string $tableName): array
    {
        $indexes = $this->db->connection->fetchAllAssociative('pragma index_list(' . $this->quote($tableName) . ')');

        $ret = [];
        foreach ($indexes as $index) {
            if (str_starts_with((string) $index['name'], 'sqlite_')) {
                continue;
            }

            $res = $this->db->connection->fetchAllAssociative('pragma index_info(' . $this->quote((string) $index['name']) . ')');

            $indexInfo = [
                'columns' => array_map(static fn (array $row): string => (string) $row['name'], $res),
                'name' => (string) $index['name'],
            ];

            if ((bool) $index['unique']) {
                $indexInfo['type'] = 'unique';
            }

            $ret[] = $indexInfo;
        }

        return $ret;
    }

    public function getForeignKeys(string $tableName): array
    {
        $fks = $this->db->connection->fetchAllAssociative('pragma foreign_key_list(' . $this->quote($tableName) . ')');

        $ret = [];
        foreach ($fks as $fk) {
            $id = (int) $fk['id'];
            if (!isset($ret[$id])) {
                $ret[$id] = [
                    // SQLite foreign keys are unnamed
                    'name' => '',
                    'columns' => [(string) $fk['from']],
                    'referencedColumns' => [(string) $fk['to']],
                    'referencedTable' => (string) $fk['table'],
                    'onUpdate' => strtoupper((string) $fk['on_update']),
                    'onDelete' => strtoupper((string) $fk['on_delete']),
                ];
            } else {
                $ret[$id]['columns'][] = (string) $fk['from'];
                $ret[$id]['referencedColumns'][] = (string) $fk['to'];
            }
        }

        return array_values($ret);
    }

    /**
     * @param array<string, mixed> $column pragma table_info row
     *
     * @return array{type: string, args?: list<mixed>}
     */
    public static function toStrapiType(array $column): array
    {
        $type = (string) $column['type'];
        preg_match('/[^(), ]+/', strtolower($type), $m);
        $rootType = $m[0] ?? '';

        switch ($rootType) {
            case 'integer':
            case 'int':
                if ((int) ($column['pk'] ?? 0) > 0) {
                    return ['type' => 'increments', 'args' => [['primary' => true, 'primaryKey' => true]]];
                }

                return ['type' => 'integer'];
            case 'float':
            case 'double':
            case 'real':
                return ['type' => 'float', 'args' => [10, 2]];
            case 'bigint':
                return ['type' => 'bigInteger'];
            case 'varchar':
                $length = substr($type, 8, strlen($type) - 9);

                return ['type' => 'string', 'args' => [(int) $length]];
            case 'text':
            case 'clob':
                return ['type' => 'text', 'args' => ['longtext']];
            case 'json':
                return ['type' => 'jsonb'];
            case 'boolean':
                return ['type' => 'boolean'];
            case 'datetime':
                return ['type' => 'datetime', 'args' => [['useTz' => false, 'precision' => 6]]];
            case 'date':
                return ['type' => 'date'];
            case 'time':
                return ['type' => 'time', 'args' => [['precision' => 3]]];
            default:
                return ['type' => 'specificType', 'args' => [$column['type']]];
        }
    }

    private function quote(string $identifier): string
    {
        return $this->db->connection->quoteSingleIdentifier($identifier);
    }
}
