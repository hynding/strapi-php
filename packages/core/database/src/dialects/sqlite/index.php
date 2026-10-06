<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Sqlite;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Strapi\Database\Database;
use Strapi\Database\Dialects\Dialect;
use Strapi\Database\Errors\NotNullError;
use Strapi\Database\Fields\Shared\Parsers;

/** Port of packages/core/database/src/dialects/sqlite/index.ts. */
final class Sqlite extends Dialect
{
    private const UNSUPPORTED_OPERATORS = ['$jsonSupersetOf'];

    /** SQLite compound SELECT limit (SQLITE_MAX_COMPOUND_SELECT). */
    private const SQLITE_BATCH_INSERT_SIZE = 500;

    public function __construct(Database $db)
    {
        parent::__construct($db, 'sqlite');
        $this->schemaInspector = new SchemaInspector($db);
    }

    public function getBatchInsertSize(): int
    {
        return self::SQLITE_BATCH_INSERT_SIZE;
    }

    public function configure(array &$connection): void
    {
        $filename = $connection['filename'] ?? null;
        if (is_string($filename) && $filename !== ':memory:' && $filename !== '') {
            $dir = dirname($filename);
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            $resolved = realpath($dir);
            $connection['filename'] = ($resolved !== false ? $resolved : $dir) . DIRECTORY_SEPARATOR . basename($filename);
        }
    }

    public function useReturning(): bool
    {
        return true;
    }

    public function initialize(Connection $connection): void
    {
        $connection->executeStatement('pragma foreign_keys = on');
    }

    public function canAlterConstraints(): bool
    {
        return false;
    }

    public function getSqlType(string $type): string
    {
        return match ($type) {
            'enum' => 'text',
            'double', 'decimal' => 'float',
            'timestamp' => 'datetime',
            default => $type,
        };
    }

    public function supportsOperator(string $operator): bool
    {
        return !in_array($operator, self::UNSUPPORTED_OPERATORS, true);
    }

    public function startSchemaUpdate(): void
    {
        $this->db->connection->executeStatement('pragma foreign_keys = off');
    }

    public function endSchemaUpdate(): void
    {
        $this->db->connection->executeStatement('pragma foreign_keys = on');
    }

    public function transformErrors(\Throwable $error): never
    {
        if ($error instanceof DriverException && ($error->getSQLState() === '23000' || $error->getCode() === 19) && str_contains($error->getMessage(), 'NOT NULL')) {
            throw new NotNullError(self::extractColumn($error), $error);
        }

        parent::transformErrors($error);
    }

    public function canAddIncrements(): bool
    {
        return false;
    }

    /** Knex stores JS Dates in SQLite as the epoch in milliseconds; booleans as 0/1. */
    public function toDatabaseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return Parsers::toMilliseconds($value);
        }

        return parent::toDatabaseValue($value);
    }

    public function toDbalColumn(array $column): array
    {
        [$type, $options] = parent::toDbalColumn($column);

        // Knex creates `float`/`double`/`decimal` as FLOAT, `json`/`jsonb` as JSON and `text` as TEXT on
        // SQLite; DBAL would declare DOUBLE PRECISION / CLOB, which the inspector reads back as other types.
        if (in_array($column['type'], ['double', 'decimal', 'float'], true)) {
            return ['float', $options + ['columnDefinition' => $this->declaration('float', $options)]];
        }
        if (in_array($column['type'], ['json', 'jsonb'], true)) {
            return ['json', $options + ['columnDefinition' => $this->declaration('json', $options)]];
        }
        if ($column['type'] === 'text') {
            return ['text', $options + ['columnDefinition' => $this->declaration('text', $options)]];
        }

        return [$type, $options];
    }

    /** @param array<string, mixed> $options */
    private function declaration(string $sqlType, array $options): string
    {
        $sql = $sqlType;
        if (!empty($options['notnull'])) {
            $sql .= ' NOT NULL';
        } elseif (!array_key_exists('default', $options)) {
            $sql .= ' DEFAULT NULL';
        }
        if (array_key_exists('default', $options) && $options['default'] !== null) {
            $default = $options['default'];
            $sql .= ' DEFAULT ' . (is_int($default) || is_float($default) ? (string) $default : "'" . str_replace("'", "''", (string) $default) . "'");
        }

        return $sql;
    }
}
