<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Mysql;

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;
use Strapi\Database\Dialects\Dialect;

/** Port of packages/core/database/src/dialects/mysql/index.ts. */
final class Mysql extends Dialect
{
    public DatabaseInspector $databaseInspector;

    /** @var array{database: string|null, version: string|null}|null */
    public ?array $info = null;

    public function __construct(Database $db)
    {
        parent::__construct($db, 'mysql');
        $this->schemaInspector = new SchemaInspector($db);
        $this->databaseInspector = new DatabaseInspector($db);
    }

    public function initialize(Connection $connection): void
    {
        try {
            $connection->executeStatement('set session sql_require_primary_key = 0;');
        } catch (\Throwable) {
            // Ignore error due to lack of session permissions
        }

        if ($this->info === null) {
            $this->info = $this->databaseInspector->getInformation($connection);
        }
    }

    public function startSchemaUpdate(): void
    {
        try {
            $this->db->connection->executeStatement('set foreign_key_checks = 0;');
            $this->db->connection->executeStatement('set session sql_require_primary_key = 0;');
        } catch (\Throwable) {
            // Ignore error due to lack of session permissions
        }
    }

    public function endSchemaUpdate(): void
    {
        $this->db->connection->executeStatement('set foreign_key_checks = 1;');
    }

    public function supportsUnsigned(): bool
    {
        return true;
    }

    /** Knex column declarations (precision on datetime/time, LONGTEXT, DOUBLE, DECIMAL(10,2)). */
    public function toDbalColumn(array $column): array
    {
        [$type, $options] = parent::toDbalColumn($column);
        $args = $column['args'] ?? [];
        $unsigned = !empty($options['unsigned']) ? ' unsigned' : '';

        $definition = match ($column['type']) {
            'datetime' => 'datetime(' . (int) ($args[0]['precision'] ?? 6) . ')',
            'timestamp' => 'timestamp(' . (int) ($args[0]['precision'] ?? 6) . ')',
            'time' => 'time(' . (int) ($args[0]['precision'] ?? 3) . ')',
            'text' => ($args[0] ?? 'text') === 'longtext' ? 'longtext' : (($args[0] ?? 'text') === 'mediumtext' ? 'mediumtext' : 'text'),
            'double', 'float' => 'double' . $unsigned,
            'decimal' => 'decimal(' . (int) ($args[0] ?? 10) . ', ' . (int) ($args[1] ?? 2) . ')' . $unsigned,
            'json', 'jsonb' => 'json',
            'boolean' => 'boolean',
            default => null,
        };

        if ($definition !== null) {
            $options['columnDefinition'] = $this->declaration($definition, $options);
        }

        return [$type, $options];
    }

    /** @param array<string, mixed> $options */
    private function declaration(string $sqlType, array $options): string
    {
        $sql = $sqlType;
        if (!empty($options['notnull'])) {
            $sql .= ' NOT NULL';
        } else {
            $sql .= ' NULL';
        }
        if (array_key_exists('default', $options) && $options['default'] !== null) {
            $default = $options['default'];
            $sql .= ' DEFAULT ' . (is_int($default) || is_float($default) ? (string) $default : (is_bool($default) ? ($default ? '1' : '0') : "'" . str_replace("'", "''", (string) $default) . "'"));
        }

        return $sql;
    }

    public function usesForeignKeys(): bool
    {
        return true;
    }
}
