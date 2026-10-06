<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Postgresql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Strapi\Database\Database;
use Strapi\Database\Dialects\Dialect;
use Strapi\Database\Errors\NotNullError;

/** Port of packages/core/database/src/dialects/postgresql/index.ts. */
final class Postgresql extends Dialect
{
    public function __construct(Database $db)
    {
        parent::__construct($db, 'postgres');
        $this->schemaInspector = new SchemaInspector($db);
    }

    public function useReturning(): bool
    {
        return true;
    }

    public function initialize(Connection $connection): void
    {
        // If we're using a schema, set the default path for all table names in queries to use that schema
        $schemaName = $this->db->getSchemaName();
        if ($schemaName !== null && $schemaName !== '') {
            $connection->executeStatement('SET search_path TO ' . $connection->quoteSingleIdentifier($schemaName));
        }
    }

    public function usesForeignKeys(): bool
    {
        return true;
    }

    /** Knex column declarations (timestamp(6)/time(3) precision, jsonb, double precision, decimal(10,2)). */
    public function toDbalColumn(array $column): array
    {
        [$type, $options] = parent::toDbalColumn($column);
        $args = $column['args'] ?? [];

        $definition = match ($column['type']) {
            'datetime', 'timestamp' => 'timestamp(' . (int) ($args[0]['precision'] ?? 6) . ')' . (($args[0]['useTz'] ?? false) ? 'tz' : ''),
            'time' => 'time(' . (int) ($args[0]['precision'] ?? 3) . ')' . (($args[0]['useTz'] ?? false) ? 'tz' : ''),
            'json', 'jsonb' => $column['type'] === 'jsonb' ? 'jsonb' : 'json',
            'double', 'float' => 'double precision',
            'decimal' => 'decimal(' . (int) ($args[0] ?? 10) . ', ' . (int) ($args[1] ?? 2) . ')',
            'text' => 'text',
            default => null,
        };

        if ($definition !== null) {
            $sql = $definition . (!empty($options['notnull']) ? ' not null' : '');
            if (array_key_exists('default', $options) && $options['default'] !== null) {
                $default = $options['default'];
                $sql .= ' default ' . (is_int($default) || is_float($default) ? (string) $default : (is_bool($default) ? ($default ? 'true' : 'false') : "'" . str_replace("'", "''", (string) $default) . "'"));
            }
            $options['columnDefinition'] = $sql;
        }

        return [$type, $options];
    }

    public function canRenameSchemaObjects(): bool
    {
        return true;
    }

    public function getSqlType(string $type): string
    {
        return match ($type) {
            'timestamp' => 'datetime',
            default => $type,
        };
    }

    public function transformErrors(\Throwable $error): never
    {
        if ($error instanceof DriverException && $error->getSQLState() === '23502') {
            throw new NotNullError(self::extractColumn($error), $error);
        }

        parent::transformErrors($error);
    }

    /**
     * Get column type conversion SQL with USING clause for PostgreSQL.
     *
     * @return array{sql: string, typeClause: string, warning?: string}|null
     */
    public function getColumnTypeConversionSQL(string $currentType, string $targetType): ?array
    {
        if ($targetType === 'datetime' && $currentType === 'time without time zone') {
            return [
                'sql' => 'ALTER TABLE %1$s ALTER COLUMN %2$s TYPE timestamp(6) USING (\'1970-01-01 \' || %2$s::text)::timestamp',
                'typeClause' => 'timestamp(6)',
                'warning' => 'Time values will be converted to datetime with default date "1970-01-01". Original time values will be preserved.',
            ];
        }

        if ($targetType === 'time' && $currentType === 'timestamp without time zone') {
            return [
                'sql' => 'ALTER TABLE %1$s ALTER COLUMN %2$s TYPE time(3) USING %2$s::time',
                'typeClause' => 'time(3)',
                'warning' => 'Datetime values will be converted to time only. Date information will be lost.',
            ];
        }

        return null;
    }
}
