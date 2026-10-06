# strapi/database

Database layer on Doctrine DBAL 4: metadata, schema sync, query builder, entity manager, migrations
(port of `@strapi/database`).

| | |
| --- | --- |
| Upstream | [`@strapi/database`](https://github.com/strapi/strapi/tree/develop/packages/core/database) |
| Namespace | `Strapi\Database\` |
| Status | `foundation` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository root
for the naming rules. Everything is synchronous: where upstream `await`s, we call.

The port is **wire-compatible with Node Strapi**: table, column, join-table and index names are
produced by the same algorithms (lodash `snakeCase`, the SHAKE256-hashed identifier shortener) and
the DDL yields the same catalog, so one database can be shared by both backends
(`tests/SharedDatabaseTest.php` opens a database created by Knex and finds it `UNCHANGED`).

## Usage

```php
use Strapi\Database\Database;
use Strapi\Database\Utils\SchemaFactory;

$db = new Database([
    'connection' => ['client' => 'sqlite', 'connection' => ['filename' => '.tmp/data.db'], 'useNullAsDefault' => true],
    'settings' => ['forceMigration' => true, 'runMigrations' => true, 'migrations' => ['dir' => 'database/migrations']],
    'logger' => $psrLogger, // optional
]);

$schemas = SchemaFactory::builtinSchemas();                       // admin::user, plugin::upload.*, plugin::users-permissions.*
$schemas['api::article.article'] = SchemaFactory::fromJsonFile('src/api/article/content-types/article/schema.json');
$schemas['basic.simple'] = SchemaFactory::fromJsonFile('src/components/basic/simple.json');

$db->init($schemas);          // Schema objects keyed by uid (content types AND components), or raw models
$db->schema->sync();          // migrations + 3-way diff + DDL + stored schema hash

$articles = $db->query('api::article.article');
$article = $articles->create(['data' => ['title' => 'Hello', 'categories' => [1, 2]], 'populate' => ['categories']]);
$articles->update(['where' => ['id' => $article['id']], 'data' => ['categories' => ['connect' => [['id' => 3, 'position' => ['before' => 1]]]]]]);
$page = $articles->findPage(['where' => ['categories' => ['name' => ['$containsi' => 'news']]], 'orderBy' => ['title' => 'asc'], 'page' => 1, 'pageSize' => 10, 'populate' => '*']);
```

## Ported files

| Upstream | PHP | Public API |
| --- | --- | --- |
| `index.ts` | `src/index.php` | `Database` (implements `Strapi\Types\Modules\Database\Database`): `__construct(array $config)`, `static create($config, $models)`, `init(array $models): static`, `query(uid): EntityRepository`, `metadata(uid)`, `transaction(?callable)` (callback receives `['trx', 'commit', 'rollback', 'onCommit', 'onRollback']`; without callback returns `TransactionObject`), `inTransaction()`, `getConnection(): DBAL\Connection`, `getSchemaConnection(): AbstractSchemaManager`, `sql(): SqlBuilder`, `getSchemaName()`, `getInfo()`, `queryBuilder(uid)`, `destroy()`; public `$connection`, `$dialect`, `$metadata`, `$schema`, `$migrations`, `$lifecycles`, `$entityManager`, `$repair`, `$logger`, `$config`. |
| `connection.ts` | `src/connection.php` | `Connection::toDbalParams($connectionConfig)` (sqlite→`pdo_sqlite` with `path`/`memory`, mysql/mysql2→`pdo_mysql`, postgres→`pdo_pgsql`; host/port/user/password/database/charset/socketPath/ssl/schema), `createConnection()`, `isDatabaseClientKind()`. |
| `dialects/*` | `src/dialects/*` | `Dialect` (abstract): `configure()`, `initialize()`, `getSqlType()`, `canAlterConstraints()`, `usesForeignKeys()`, `useReturning()`, `supportsUnsigned()`, `supportsWindowFunctions()`, `supportsOperator()`, `startSchemaUpdate()`/`endSchemaUpdate()`, `canAddIncrements()`, `getBatchInsertSize()`, `transformErrors()`, `toDatabaseValue()`, `toDbalColumn()`; `Sqlite`, `Mysql` (+ `DatabaseInspector`, `Constants`), `Postgresql` (+ `getColumnTypeConversionSQL()`); `SchemaInspector` interface + one per dialect (`getSchema()`, `getTables()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`); `Dialects::getDialect()`. |
| `metadata/*` | `src/metadata/*` | `Metadata` (iterable map): `create($models)`, `loadModels()`, `get()`, `has()`, `add()`, `set()`, `delete()`, `keys()`, `values()`, `updateAttribute()`, `validate()`, `identifiers()`; `Relations` (`createRelation`, `isBidirectional`, `isOneToAny`, `isManyToAny`, `isAnyToOne`, `isAnyToMany`, `isPolymorphic`, `hasOrderColumn`, `hasInverseOrderColumn`, `isOwner`, `shouldUseJoinTable`); `AttributeNaming::columnName/joinColumnName/joinTableName`. |
| `fields/*` | `src/fields/*` | `Fields::createField($attribute)`; `Field`, `StringField`, `JsonField`, `BigIntegerField`, `NumberField`, `BooleanField`, `DateField`, `TimeField`, `DatetimeField`, `TimestampField` with `toDB()`/`fromDB()`; `Shared\Parsers::parseDate/parseTime/parseDateTimeOrTimestamp/toDateTime/toIsoString/toMilliseconds/toDatabaseString`. |
| `schema/*` | `src/schema/*` | `SchemaProvider` (`db.schema`): `getSchema()`, `invalidate()`, `sync()`, `syncSchema()`, `create()`, `drop()`, `reset()`, `$builder`, `$schemaDiff`, `$schemaStorage`; `Schema::metadataToSchema()`, `createTable()`, `createColumn()`, `getColumnType()`; `Diff::diff()` (+ `setPersistedTablesProvider()` for the core-store `persisted_tables` lookup upstream does via `strapi.store`); `Builder` (`createSchema`, `createTables`, `dropSchema`, `updateSchema`, `createTable`, `alterTable`, `dropTable`, `createTableForeignKeys`, `dropTableForeignKeys`, `toDbalTable/Column/Index/ForeignKey`); `Storage` (`read`, `add`, `clear`, `hashSchema`) on `strapi_database_schema`; `Types` constants and phpstan shapes. |
| `migrations/*` | `src/migrations/*` | `Migrations` (`db.migrations`): `shouldRun()`, `up()`, `down()`, `$users`, `$internal` (`register()`); `Runner::pending/up/down`; `Storage` (`strapi_migrations`, `strapi_migrations_internal`); `Discover::discoverMigrationFiles()` (`*.php`, `*.sql`); `Resolver` (a `.php` migration returns `['up' => fn(Connection $trx, Database $db), 'down' => ...]`); `Common::wrapTransaction()`; `Logger`; `Heartbeat`; `InternalMigrations::all()`. |
| `query/*` | `src/query/*` | `QueryBuilder` (`db.queryBuilder(uid)`): `select`, `addSelect`, `insert`, `onConflict`, `merge`, `ignore`, `delete`, `update`, `increment`, `decrement`, `count`, `max`, `min`, `where`, `limit`, `offset`, `orderBy`, `groupBy`, `populate`, `search`, `transacting`, `forUpdate`, `init($params)`, `filters`, `first`, `join`, `ref`, `raw`, `aliasColumn`, `getAlias`, `processState`, `getSqlQuery(): SqlBuilder`, `toSql()`, `execute(['mapResults' => bool])`; `SqlBuilder` (the Knex-like layer over DBAL: `from/select/distinct/leftJoin/innerJoin/on/onVal/where/orWhere/whereNot/whereIn/whereNotIn/whereNull/whereNotNull/whereBetween/whereRaw/orderBy/groupBy/limit/offset/insert/returning/onConflict/merge/ignore/update/delete/truncate/count/max/min/toSql/run`); `Raw`; helpers `Where::processWhere/applyWhere/qualifyRootColumns`, `OrderBy::processOrderBy/wrapWithDeepSort/buildStatusSortExpression`, `Join::createJoin/createPivotJoin/applyJoins`, `Search::applySearch/escapeQuery`, `Transform::toRow/fromRow/toColumnName`, `Populate\Process::processPopulate`, `Populate\Apply::applyPopulate`. |
| `entity-manager/*` | `src/entity-manager/*` | `EntityManager`: `findOne`, `findMany`, `count`, `create`, `createMany`, `update`, `updateMany`, `delete`, `deleteMany`, `clone`, `populate`, `load`, `attachRelations`, `updateRelations`, `deleteRelations`, `insertJoinTableRows`, `createQueryBuilder`, `getRepository`; `EntityRepository` (implements `Strapi\Types\Modules\Database\EntityRepository`): the same plus `findWithCount`, `findPage`, `loadPages`; `RelationsOrderer::create($initArr, $idColumn, $orderColumn, $strict)->connect()->disconnect()->get()->getOrderMap()`, `RelationsOrderer::sortConnectArray()`; `RegularRelations` (`deletePreviousOneToAnyRelations`, `deletePreviousAnyToOneRelations`, `deleteRelations`, `cleanOrderColumns`); `MorphRelations` (`deleteRelatedMorphOneRelationsAfterMorphToManyUpdate`, `encodePolymorphicId`, `encodePolymorphicRelation`). |
| `lifecycles/*` | `src/lifecycles/*` | `Lifecycles` (`db.lifecycles`): `subscribe(callable|array): callable`, `clear()`, `run($action, $uid, &$properties, $states)`, `createEvent()`, `enable()`, `disable()`; `Event` (`action`, `model`, `params`, `result`, `state`, all mutable); subscribers `Timestamps`, `ModelsLifecycles` (runs the schema's `config.lifecycles`). |
| `errors/*` | `src/errors/*` | `DatabaseError` (extends `Strapi\Utils\Errors\ApplicationError`, status 500), `NotNullError`, `InvalidTimeError`, `InvalidDateError`, `InvalidDateTimeError`, `InvalidRelationError`, same `name`s as upstream. |
| `transaction-context.ts` | `src/transaction-context.php`, `src/transaction-object.php` | `TransactionContext::run/get/commit/rollback/onCommit/onRollback/push` (a stack instead of AsyncLocalStorage); `TransactionObject::commit/rollback/get/onCommit/onRollback`. |
| `utils/*` | `src/utils/*` | `Identifiers\Identifiers` (`global()`, `setGlobal()`, `getName`, `getTableName`, `getJoinTableName`, `getMorphTableName`, `getColumnName`, `getJoinColumnAttributeIdName`, `getInverseJoinColumnAttributeIdName`, `getOrderColumnName`, `getInverseOrderColumnName`, `getMorphColumn*Name`, `get*IndexName`, `getShortenedName`, `getNameFromTokens`, `getUnshortenedName`), `Identifiers\Hash::createHash` (pure-PHP SHAKE256); `Types`; `AsyncCurry`; `LodashWords` (lodash `words`/`snakeCase`/`kebabCase`/`camelCase`); **new** `SchemaFactory` (see below). |
| `validations/*` | `src/validations/*` | `Validations::validateDatabase()`, `Relations\Bidirectional::validateBidirectionalRelations()` (warnings go to the PSR logger). |
| `repairs/*` | `src/repairs/*` | `Repairs` (`db.repair`): `removeOrphanMorphType(['pivot' => ...])`, `processUnidirectionalJoinTables(callable)`. |
| — (`packages/core/core/src/utils/transform-content-types-to-models.ts`, `domain/content-type`, `loaders/*`, i18n `register.ts`) | `src/utils/schema-factory.php` | `SchemaFactory::fromJsonFile($path, $uid = null, $lifecycles = [])`, `fromArray()`, `contentType()`, `component()`, `toModels(iterable<Schema>)`, `builtinSchemas()`, `coreStoreModel()`, `transformAttribute(s)()`, `getComponentJoinTableName()` & co. Computes uid/modelName/globalId/collectionName like core, adds `createdAt`/`updatedAt`/`publishedAt`/`createdBy`/`updatedBy`/`locale`/`localizations`, and turns components, dynamic zones and media into the relation shapes the database understands. Core should reuse it. |

## Naming rules (byte-identical to Node Strapi)

All names go through `Identifiers` with `maxLength = 55` and lodash `snakeCase`; tokens longer than
their share are cut and suffixed with 5 hex chars of SHAKE256 (`components_default_long_component_names`
+ `complexes` + `links` at 25 → `compon56bca_complexes_lnk`).

| Thing | Rule | getstarted examples |
| --- | --- | --- |
| Table | `snake(collectionName)` | `articles`, `kitchensinks`, `components_basic_simples`, `up_users` |
| Scalar column | `snake(attribute)` / `columnName` override | `blocks_content`, `document_id`, `created_at`, `published_at`, `locale`, `created_by_id` (join column of `createdBy`, `useJoinTable: false`) |
| Join table | `<table>_<snake(attr)>_lnk` (owner side only) | `articles_categories_lnk`, `kitchensinks_one_way_tag_lnk`, `temps_category_lnk` |
| Join columns | `<snake(singularName)>_id` / inverse `<snake(targetSingular)>_id`, `inv_<...>_id` when self-referencing | `article_id`, `category_id`; `upload_folders_parent_lnk(folder_id, inv_folder_id)` |
| Order columns | `<targetSingular>_ord` on xToMany, inverse `<singular>_ord` on bidirectional manyToX, `inv_<singular>_ord` on self manyToMany | `articles_categories_lnk(category_ord, article_ord)`, `kitchensinks_many_to_one_tag_lnk(kitchensink_ord)` |
| Join-table indexes | `<lnk>_fk`, `<lnk>_ifk`, `<lnk>_uq`, `<lnk>_ofk`, `<lnk>_oifk` | `articles_categories_lnk_uq` |
| Morph table (morphToMany) | `<table>_<attr>_mph` with `<singular>_id`, `<attr>_id`, `<attr>_type`, `field`, `order`; indexes `_fk`, `_oidx`, `_idix` | `files_related_mph(file_id, related_id, related_type, field, order)` |
| Morph columns (morphToOne) | `<attr>_id`, `<attr>_type` (`target_id`/`target_type` unless `morphColumn` given) | `tags(taggable_id, taggable_type)` |
| Components / dynamic zones | one `<collectionName>_cmps` table per content type: `id, entity_id, cmp_id, component_type, field, order`; indexes `<cn>_field_idx`, `<cn>_component_type_idx`, `<cn>_entity_fk`, `<cn>_uq` | `kitchensinks_cmps`, `addresses_cmps` |
| Document index | `<collectionName>_documents_idx (document_id, locale, published_at)` | `articles_documents_idx` |
| FK index for join columns | `<table>_<column>_fk` | `articles_created_by_id_fk` |

## Deviations from upstream

- **Knex → DBAL.** Doctrine DBAL has no nested-callback query builder, so `src/query/sql-builder.php`
  implements the Knex subset Strapi uses (quoted identifiers, nested `where` groups, `??` identifier
  placeholders in raw SQL, `ON CONFLICT`/`ON DUPLICATE KEY`, `RETURNING`, derived tables) and compiles
  to SQL + bindings; DDL uses DBAL `Table`/`Column`/`Index`/`ForeignKeyConstraint`/`TableDiff` and the
  platform SQL (SQLite table rebuilds come from `SQLitePlatform::getAlterTableSQL`). Column
  declarations are forced to what Knex emits (`float`/`json`/`text` on SQLite, `datetime(6)`,
  `longtext`, `decimal(10, 2)` on MySQL, `timestamp(6)`, `jsonb` on PostgreSQL) so the schema
  inspectors read back the same types and the diff stays stable.
- **Values on the wire.** Datetimes are stored as the epoch in milliseconds on SQLite (what
  better-sqlite3 does with a `Date`) and as `Y-m-d H:i:s.u` UTC elsewhere; booleans as `0/1`;
  `DatetimeField::fromDB` returns ISO strings (`toISOString()`), `TimestampField::fromDB` the epoch in
  ms as a string, like upstream.
- `Database::init()` is the instance method of the `Strapi\Types` contract; the static convenience is
  `Database::create($config, $models)` (PHP cannot overload). `init()` accepts `Schema` objects
  (content types and components, keyed by uid) and converts them with `SchemaFactory::toModels()`,
  which is core's `transformContentTypesToModels`; raw upstream-shaped models are accepted too.
- Lodash's `snakeCase` splits digits into their own words (`componen56bca` → `componen_56_bca`); the
  lighter `Strapi\Utils\Primitives\Strings::snakeCase` does not, so the database package carries a
  faithful `LodashWords` port and uses it for every identifier.
- PHP has no SHAKE256, so `Identifiers\Hash` implements Keccak-f[1600]; verified against Node's
  `crypto.createHash('shake256')` and the upstream test vectors.
- `TransactionContext` is a stack (no AsyncLocalStorage): the DBAL connection is the transaction.
  `db.transaction()` without a callback pushes a frame that `commit()`/`rollback()` pop.
- `Diff` reads `persisted_tables` through an injectable provider instead of the global `strapi.store`.
- `Lifecycles::run()` takes `$properties` by reference so the timestamps subscriber can mutate `params.data`
  (JS mutates the object in place).
- `EntityManager::toAssocs()` resolves `{ documentId, locale?, status? }` relation targets to row ids
  (published first, then draft) before linking, as the document service expects; `clone()` is added
  for the document service.
- Join-table order compaction (`cleanOrderColumns`) uses `UPDATE ... FROM` on PostgreSQL/SQLite ≥ 3.33,
  the multi-table `UPDATE` on MySQL and a per-row fallback on older SQLite.
- Logging goes to a PSR-3 logger (`NullLogger` by default); upstream `console`/`debug` output is dropped.
- `MysqlDialect.configure()`'s mysql2 `typeCast` has no equivalent: DECIMAL/TINYINT(1) conversion is
  done by the fields (`NumberField`, `BooleanField`) instead.

## Stubbed / not ported

| Upstream | Status |
| --- | --- |
| `migrations/internal-migrations/5.0.0-*` | Registered under their upstream names as **no-ops** so `strapi_migrations_internal` stays identical; the v4 → v5 data migrations themselves (identifier renames, document ids) are not ported (`TODO` in `internal-migrations/index.php`). |
| `migrations/heartbeat.ts` | Ported as a working helper, but nothing uses it (the document-id migration that did is not ported). |
| `migrations/file-builder.ts`, `schema/rename-helpers.ts`, `Dialect::renameSchemaObject/dropSchemaObject` | Not ported: content-type-builder rename migrations (generated migration files). `canRenameSchemaObjects()` is kept. |
| `query/helpers/streams/*` (`QueryBuilder.stream()`) | Not ported (Node streams). |
| `Dialect::getColumnTypeConversionSQL` + `Builder::handleSpecialTypeConversions` | The PostgreSQL time ↔ datetime `USING` conversion SQL is exposed by `Postgresql::getColumnTypeConversionSQL()` but the builder does not apply it yet. |
| `index.ts` connection functions (`connection.connection` as a function), knex `pool.afterCreate` | Not applicable: DBAL opens one connection, `Dialect::initialize()` runs on it. |

## Test fixtures

- `tests/fixtures/getstarted/` — the upstream `examples/getstarted` schemas (same paths).
- `tests/fixtures/metadata/*.json` — upstream `metadata/__tests__/resources/*.ts` dumped to JSON.
- `tests/fixtures/getstarted-schema.json`, `getstarted-metadata.json` — the target schema and column maps
  produced by **upstream TypeScript** (`transformContentTypesToModels` + `createMetadata` + `metadataToSchema`,
  run with `tsx` against `/tmp/strapi`) over the getstarted schemas; `SchemaParityTest` asserts equality.
- `tests/fixtures/getstarted-sqlite.json`, `getstarted-knex.db` — the SQLite catalog and a database
  created by **Knex 3 / better-sqlite3** from that schema (with the stored schema hash, migration log and a
  sample row); `SharedDatabaseTest` opens it with this port and asserts `UNCHANGED` plus matching DDL.
