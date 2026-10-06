<?php

declare(strict_types=1);

namespace Strapi\Database\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Strapi\Database\Database;
use Strapi\Database\Fields\Shared\Parsers;

/**
 * Port of packages/core/database/src/schema/storage.ts: persists the last synced schema and its
 * hash in `strapi_database_schema`.
 *
 * @phpstan-import-type Schema as SchemaArray from Types
 */
final class Storage
{
    public const TABLE_NAME = 'strapi_database_schema';

    public function __construct(private readonly Database $db)
    {
    }

    private function hasSchemaTable(): bool
    {
        return $this->db->connection->createSchemaManager()->tableExists(self::TABLE_NAME);
    }

    private function createSchemaTable(): void
    {
        $table = new Table(self::TABLE_NAME, [
            new Column('id', 'integer', ['autoincrement' => true, 'notnull' => true]),
            new Column('schema', 'json', ['notnull' => false]),
            new Column('time', 'datetime', ['notnull' => false]),
            new Column('hash', 'string', ['notnull' => false, 'length' => 255]),
        ]);
        $table = $table->edit()->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())->create();
        $this->db->connection->createSchemaManager()->createTable($table);
    }

    private function checkTableExists(): void
    {
        if (!$this->hasSchemaTable()) {
            $this->createSchemaTable();
        }
    }

    /** @return array{id: int, time: mixed, hash: string, schema: SchemaArray}|null */
    public function read(): ?array
    {
        $this->checkTableExists();

        $q = $this->db->connection->quoteSingleIdentifier(...);

        // NOTE: get the ID first before fetching the exact entry for performance on MySQL/MariaDB
        $id = $this->db->connection->fetchOne(sprintf('SELECT %s FROM %s ORDER BY %s DESC LIMIT 1', $q('id'), $q(self::TABLE_NAME), $q('time')));
        if ($id === false || $id === null) {
            return null;
        }

        $res = $this->db->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $q(self::TABLE_NAME), $q('id')), [$id]);
        if ($res === false) {
            return null;
        }

        $schema = is_string($res['schema']) ? json_decode($res['schema'], true, 512, JSON_THROW_ON_ERROR) : $res['schema'];

        return [
            'id' => (int) $res['id'],
            'time' => $res['time'],
            'hash' => (string) $res['hash'],
            'schema' => $schema,
        ];
    }

    /** @param SchemaArray $schema */
    public function hashSchema(array $schema): string
    {
        // Sort tables by name for deterministic hashing regardless of insertion order
        $tables = $schema['tables'];
        usort($tables, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return hash('sha256', self::encode(['tables' => $tables] + $schema));
    }

    /** @param SchemaArray $schema */
    public function add(array $schema): void
    {
        $this->checkTableExists();

        $q = $this->db->connection->quoteSingleIdentifier(...);

        // NOTE: we can remove this to add history
        $this->db->connection->executeStatement(sprintf('DELETE FROM %s', $q(self::TABLE_NAME)));

        $this->db->connection->insert(self::TABLE_NAME, [
            $q('schema') => self::encode($schema),
            $q('hash') => $this->hashSchema($schema),
            $q('time') => $this->db->dialect->toDatabaseValue(new \DateTimeImmutable()),
        ]);
    }

    public function clear(): void
    {
        $this->checkTableExists();

        $this->db->connection->executeStatement(sprintf('DELETE FROM %s', $this->db->connection->quoteSingleIdentifier(self::TABLE_NAME)));
    }

    /** JSON.stringify-compatible encoding (no escaped slashes/unicode, empty arrays stay `[]`). */
    private static function encode(mixed $value): string
    {
        return json_encode(self::normalizeForJson($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalizeForJson(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalizeForJson($v);
            }

            return $out === [] && !array_is_list($value) ? new \stdClass() : $out;
        }

        return $value;
    }
}
