<?php

declare(strict_types=1);

namespace Strapi\Database\Schema;

use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/schema/index.ts (`createSchemaProvider`): the `db.schema`
 * object with `sync()`, `syncSchema()`, `create()`, `drop()`, `reset()`.
 *
 * @phpstan-import-type Schema as SchemaArray from Types
 */
final class SchemaProvider
{
    public Builder $builder;

    public Diff $schemaDiff;

    public Storage $schemaStorage;

    /** @var SchemaArray|null */
    private ?array $schema = null;

    public function __construct(private readonly Database $db)
    {
        $this->builder = new Builder($db);
        $this->schemaDiff = new Diff($db);
        $this->schemaStorage = new Storage($db);
    }

    /** The target schema, built from metadata on first access. @return SchemaArray */
    public function getSchema(): array
    {
        return $this->schema ??= Schema::metadataToSchema($this->db->metadata);
    }

    /** Forgets the cached schema (after metadata changes). */
    public function invalidate(): void
    {
        $this->schema = null;
    }

    /** Drops the database schema. */
    public function drop(): void
    {
        $dbSchema = $this->db->dialect->schemaInspector->getSchema();
        $this->builder->dropSchema($dbSchema);
    }

    /** Creates the database schema. */
    public function create(): void
    {
        $this->builder->createSchema($this->getSchema());
    }

    /** Resets the database schema. */
    public function reset(): void
    {
        $this->drop();
        $this->create();
    }

    /** @return 'CHANGED'|'UNCHANGED' */
    public function syncSchema(): string
    {
        $databaseSchema = $this->db->dialect->schemaInspector->getSchema();
        $storedSchema = $this->schemaStorage->read();

        /*
          3way diff - DB schema / previous metadataSchema / new metadataSchema

          - When something doesn't exist in the previous metadataSchema -> It's not tracked by us and should be ignored
          - If no previous metadataSchema => use new metadataSchema so we start tracking them and ignore everything else
        */
        ['status' => $status, 'diff' => $diff] = $this->schemaDiff->diff([
            'previousSchema' => $storedSchema['schema'] ?? null,
            'databaseSchema' => $databaseSchema,
            'userSchema' => $this->getSchema(),
        ]);

        if ($status === Types::CHANGED) {
            $this->builder->updateSchema($diff);
        }

        $this->schemaStorage->add($this->getSchema());

        return $status;
    }

    /** @return 'CHANGED'|'UNCHANGED' */
    public function sync(): string
    {
        if ($this->db->migrations->shouldRun()) {
            $this->db->migrations->up();

            return $this->syncSchema();
        }

        $oldSchema = $this->schemaStorage->read();

        if ($oldSchema === null) {
            return $this->syncSchema();
        }

        $hash = $this->schemaStorage->hashSchema($this->getSchema());

        if ($oldSchema['hash'] !== $hash) {
            return $this->syncSchema();
        }

        return Types::UNCHANGED;
    }
}
