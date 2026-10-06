<?php

declare(strict_types=1);

namespace Strapi\Database;

use Doctrine\DBAL\Connection as DbalConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Strapi\Database\Dialects\Dialect;
use Strapi\Database\Dialects\Dialects;
use Strapi\Database\EntityManager\EntityManager;
use Strapi\Database\EntityManager\EntityRepository;
use Strapi\Database\Lifecycles\Lifecycles;
use Strapi\Database\Metadata\Metadata;
use Strapi\Database\Migrations\Migrations;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\SqlBuilder;
use Strapi\Database\Repairs\Repairs;
use Strapi\Database\Schema\SchemaProvider;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Database\Validations\Validations;
use Strapi\Types\Modules\Database\Database as DatabaseContract;
use Strapi\Types\Schema\Schema;

/**
 * Port of packages/core/database/src/index.ts: the `Database` class (`strapi.db`).
 *
 * Config shape (upstream `DatabaseConfig`):
 * ```
 * [
 *   'connection' => ['client' => 'sqlite'|'mysql'|'postgres', 'connection' => [...], 'useNullAsDefault' => bool, 'pool' => [...]],
 *   'settings' => ['forceMigration' => bool, 'runMigrations' => bool, 'migrations' => ['dir' => string]],
 *   'logger' => Psr\Log\LoggerInterface (optional),
 * ]
 * ```
 *
 * @phpstan-import-type Model from Metadata
 */
final class Database implements DatabaseContract
{
    public DbalConnection $connection;

    public Dialect $dialect;

    /** @var array<string, mixed> */
    public array $config;

    public Metadata $metadata;

    public SchemaProvider $schema;

    public Migrations $migrations;

    public Lifecycles $lifecycles;

    public EntityManager $entityManager;

    public Repairs $repair;

    public LoggerInterface $logger;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = [
            ...$config,
            'settings' => [
                'forceMigration' => true,
                'runMigrations' => true,
                'migrations' => ['dir' => ''],
                ...($config['settings'] ?? []),
            ],
        ];

        $this->logger = $config['logger'] ?? new NullLogger();

        $this->dialect = Dialects::getDialect($this);

        $connectionConfig = $this->config['connection']['connection'] ?? [];
        if (is_array($connectionConfig)) {
            $this->dialect->configure($connectionConfig);
            $this->config['connection']['connection'] = $connectionConfig;
        }

        $this->metadata = Metadata::create([]);

        $this->connection = Connection::createConnection($this->config['connection']);
        $this->dialect->initialize($this->connection);

        $this->schema = new SchemaProvider($this);
        $this->migrations = new Migrations($this);
        $this->lifecycles = new Lifecycles($this);
        $this->entityManager = new EntityManager($this);
        $this->repair = new Repairs($this);
    }

    /**
     * Upstream `Database.init({ models })` convenience: constructs the database and loads the models
     * (PHP cannot overload a static and an instance `init`, hence `create`).
     *
     * @param array<string, mixed> $config
     * @param array<string, Schema>|list<Model> $models `Schema` objects keyed by uid (content types and components), or raw models
     */
    public static function create(array $config, array $models = []): static
    {
        return (new static($config))->init($models);
    }

    /**
     * Loads the models: `Schema` objects keyed by uid (content types AND components) are converted
     * with SchemaFactory::toModels(); raw upstream-shaped models (`['uid', 'singularName', 'tableName', 'attributes']`)
     * are accepted as is.
     *
     * @param array<string, Schema>|list<Model> $models
     */
    public function init(array $models): static
    {
        $rawModels = [];
        $schemas = [];
        foreach ($models as $key => $model) {
            if ($model instanceof Schema) {
                $schemas[] = $model;
            } elseif (is_array($model)) {
                $rawModels[] = $model;
            } else {
                throw new \InvalidArgumentException("Invalid model {$key}");
            }
        }

        $this->metadata->loadModels([...SchemaFactory::toModels($schemas), ...$rawModels]);
        $this->schema->invalidate();
        Validations::validateDatabase($this);

        return $this;
    }

    public function query(string $uid): EntityRepository
    {
        if (!$this->metadata->has($uid)) {
            throw new \InvalidArgumentException("Model {$uid} not found");
        }

        return $this->entityManager->getRepository($uid);
    }

    /** @return Model */
    public function metadata(string $uid): mixed
    {
        return $this->metadata->get($uid);
    }

    public function inTransaction(): bool
    {
        return TransactionContext::get() !== null;
    }

    /**
     * Run work inside a DB transaction. With a callback, it receives
     * `['trx' => Connection, 'commit' => callable, 'rollback' => callable, 'onCommit' => callable, 'onRollback' => callable]`
     * and the transaction is committed when the callback returns, rolled back when it throws.
     * Without a callback, a TransactionObject (`commit()`, `rollback()`, `get()`) is returned.
     */
    public function transaction(?callable $cb = null): mixed
    {
        $notNestedTransaction = TransactionContext::get() === null;
        $trx = $this->connection;

        if ($notNestedTransaction) {
            $trx->beginTransaction();
        }

        $object = new TransactionObject($trx, $notNestedTransaction);

        if ($cb === null) {
            if ($notNestedTransaction) {
                // keep the context alive until commit()/rollback() is called
                TransactionContext::push($trx);
            }

            return $object;
        }

        return TransactionContext::run($trx, static function () use ($cb, $object, $trx): mixed {
            try {
                $res = $cb([
                    'trx' => $trx,
                    'commit' => $object->commit(...),
                    'rollback' => $object->rollback(...),
                    'onCommit' => TransactionContext::onCommit(...),
                    'onRollback' => TransactionContext::onRollback(...),
                ]);
                $object->commit();

                return $res;
            } catch (\Throwable $error) {
                $object->rollback();
                throw $error;
            }
        });
    }

    public function getSchemaName(): ?string
    {
        $schema = $this->config['connection']['connection']['schema'] ?? null;

        return is_string($schema) && $schema !== '' ? $schema : null;
    }

    public function getConnection(): DbalConnection
    {
        return $this->connection;
    }

    /** DBAL's schema manager, the equivalent of `knex.schema`. */
    public function getSchemaConnection(?DbalConnection $trx = null): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        return ($trx ?? $this->connection)->createSchemaManager();
    }

    /** A fresh low-level SqlBuilder (Knex-like) bound to this connection. */
    public function sql(): SqlBuilder
    {
        return new SqlBuilder($this->dialect, $this->connection);
    }

    /** @return array{displayName: string, schema: string|null, client: string} */
    public function getInfo(): array
    {
        $connectionSettings = $this->config['connection']['connection'] ?? [];
        $client = $this->dialect->client;

        if ($client === 'sqlite') {
            $absolutePath = $connectionSettings['filename'] ?? '';
            $cwd = getcwd() ?: '';

            return ['displayName' => $cwd !== '' && str_starts_with((string) $absolutePath, $cwd) ? ltrim(substr((string) $absolutePath, strlen($cwd)), '/\\') : (string) $absolutePath, 'schema' => null, 'client' => $client];
        }

        return [
            'displayName' => (string) ($connectionSettings['database'] ?? ''),
            'schema' => $connectionSettings['schema'] ?? null,
            'client' => $client,
        ];
    }

    public function queryBuilder(string $uid): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder($uid);
    }

    public function destroy(): void
    {
        $this->lifecycles->clear();
        $this->connection->close();
    }
}
