<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Strapi\Database\Database;
use Strapi\Database\Errors\NotNullError;
use Strapi\Database\Fields\Shared\Parsers;

/**
 * Port of packages/core/database/src/dialects/dialect.ts.
 *
 * @phpstan-import-type Column from \Strapi\Database\Schema\Types
 */
abstract class Dialect
{
    public SchemaInspector $schemaInspector;

    public function __construct(public readonly Database $db, public readonly string $client)
    {
    }

    /** Normalises the Strapi connection config before the DBAL params are built. @param array<string, mixed> $connection */
    public function configure(array &$connection): void
    {
    }

    /** Per-connection initialisation (PRAGMAs, session variables). */
    public function initialize(Connection $connection): void
    {
    }

    /** Knex column type (`string`, `text`, `jsonb`, `datetime`...) → the type name the DB reports back. */
    public function getSqlType(string $type): string
    {
        return $type;
    }

    public function canAlterConstraints(): bool
    {
        return true;
    }

    public function usesForeignKeys(): bool
    {
        return false;
    }

    public function useReturning(): bool
    {
        return false;
    }

    public function supportsUnsigned(): bool
    {
        return false;
    }

    public function supportsWindowFunctions(): bool
    {
        return true;
    }

    public function supportsOperator(string $operator): bool
    {
        return true;
    }

    public function startSchemaUpdate(): void
    {
    }

    public function endSchemaUpdate(): void
    {
    }

    public function canAddIncrements(): bool
    {
        return true;
    }

    public function canRenameSchemaObjects(): bool
    {
        return false;
    }

    /** Max rows per batch for bulk inserts. */
    public function getBatchInsertSize(): int
    {
        return 1000;
    }

    /** Maps driver exceptions onto the errors/ classes; rethrows anything else. */
    public function transformErrors(\Throwable $error): never
    {
        if ($error instanceof NotNullConstraintViolationException) {
            throw new NotNullError(self::extractColumn($error), $error);
        }

        throw $error;
    }

    /**
     * How a PHP value is bound for the driver. Dates are stored as `Y-m-d H:i:s.u` UTC by default
     * (SQLite overrides to the epoch in milliseconds like Knex/better-sqlite3 does).
     */
    public function toDatabaseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return Parsers::toDatabaseString($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /**
     * Maps a Strapi schema column (Knex-style `{type, args, defaultTo, notNullable, unsigned}`)
     * to DBAL `Column` constructor arguments: [typeName, options].
     *
     * @param Column $column
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function toDbalColumn(array $column): array
    {
        $args = $column['args'] ?? [];
        $options = [
            'notnull' => ($column['notNullable'] ?? false) === true,
            'unsigned' => ($column['unsigned'] ?? false) === true && $this->supportsUnsigned(),
        ];

        $defaultTo = $column['defaultTo'] ?? null;
        if ($defaultTo !== null) {
            $options['default'] = is_array($defaultTo) ? ($defaultTo[0] ?? null) : $defaultTo;
        }

        switch ($column['type']) {
            case 'increments':
                return ['integer', ['autoincrement' => true, 'notnull' => true, 'unsigned' => $this->supportsUnsigned()]];
            case 'string':
                $length = is_int($args[0] ?? null) ? $args[0] : 255;

                return ['string', $options + ['length' => $length]];
            case 'text':
                return ['text', $options];
            case 'jsonb':
            case 'json':
                return ['json', $options];
            case 'integer':
                return ['integer', $options];
            case 'bigInteger':
            case 'biginteger':
                return ['bigint', $options];
            case 'double':
            case 'float':
                return ['float', $options];
            case 'decimal':
                return ['decimal', $options + ['precision' => $args[0] ?? 10, 'scale' => $args[1] ?? 2]];
            case 'boolean':
                return ['boolean', $options];
            case 'date':
                return ['date', $options];
            case 'time':
                return ['time', $options];
            case 'datetime':
            case 'timestamp':
                return ['datetime', $options];
            case 'specificType':
                return ['string', $options + ['columnDefinition' => (string) ($args[0] ?? 'TEXT')]];
            default:
                throw new \InvalidArgumentException("Unknown column type {$column['type']}");
        }
    }

    protected static function extractColumn(DriverException $error): string
    {
        if (preg_match('/NOT NULL constraint failed: [^.]+\.(\w+)/', $error->getMessage(), $m) === 1) {
            return $m[1];
        }
        if (preg_match('/column "([^"]+)"/', $error->getMessage(), $m) === 1) {
            return $m[1];
        }
        if (preg_match("/Column '([^']+)' cannot be null/", $error->getMessage(), $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
