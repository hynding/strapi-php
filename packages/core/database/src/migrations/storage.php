<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Strapi\Database\Database;

/** Port of packages/core/database/src/migrations/storage.ts: the `strapi_migrations*` log tables. */
final class Storage
{
    public function __construct(private readonly Database $db, private readonly string $tableName)
    {
    }

    private function hasMigrationTable(): bool
    {
        return $this->db->connection->createSchemaManager()->tableExists($this->tableName);
    }

    private function createMigrationTable(): void
    {
        $table = new Table($this->tableName, [
            new Column('id', 'integer', ['autoincrement' => true, 'notnull' => true]),
            new Column('name', 'string', ['notnull' => false, 'length' => 255]),
            new Column('time', 'datetime', ['notnull' => false]),
        ]);
        $table = $table->edit()->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())->create();
        $this->db->connection->createSchemaManager()->createTable($table);
    }

    public function logMigration(string $name): void
    {
        $q = $this->db->connection->quoteSingleIdentifier(...);
        $this->db->connection->insert($this->tableName, [
            $q('name') => $name,
            $q('time') => $this->db->dialect->toDatabaseValue(new \DateTimeImmutable()),
        ]);
    }

    public function unlogMigration(string $name): void
    {
        $q = $this->db->connection->quoteSingleIdentifier(...);
        $this->db->connection->executeStatement(sprintf('DELETE FROM %s WHERE %s = ?', $q($this->tableName), $q('name')), [$name]);
    }

    /** @return list<string> */
    public function executed(): array
    {
        if (!$this->hasMigrationTable()) {
            $this->createMigrationTable();

            return [];
        }

        $q = $this->db->connection->quoteSingleIdentifier(...);
        $logs = $this->db->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s', $q($this->tableName), $q('time')));

        return array_map(static fn (array $log): string => (string) $log['name'], $logs);
    }
}
