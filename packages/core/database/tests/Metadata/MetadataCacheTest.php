<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Database;
use Strapi\Database\Metadata\Metadata;
use Strapi\Database\Tests\Support\GetstartedDatabase;
use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;

/**
 * PHP port: the metadata as a boot cache entry (Metadata::toCache() / loadCache(),
 * Database::initFromCache()), what a settled production boot reads instead of building it.
 */
final class MetadataCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Identifiers::setGlobal(null);
    }

    /**
     * What the boot cache stores and reads back: var_export()ed, then evaluated.
     *
     * @param array<array-key, mixed> $entry
     * @return array<array-key, mixed>
     */
    private static function roundTrip(array $entry): array
    {
        $data = eval('return ' . var_export($entry, true) . ';');
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, Schema> */
    private static function schemasWithLifecycles(callable $beforeCreate): array
    {
        $thing = SchemaFactory::contentType([
            'collectionName' => 'things',
            'info' => ['singularName' => 'thing', 'pluralName' => 'things'],
            'options' => ['draftAndPublish' => true],
            'pluginOptions' => ['i18n' => ['localized' => true]],
            'attributes' => ['name' => ['type' => 'string'], 'data' => ['type' => 'json', 'default' => new \stdClass()]],
        ], 'api::thing.thing', ['beforeCreate' => $beforeCreate]);

        return ['api::thing.thing' => $thing] + GetstartedDatabase::schemas();
    }

    /**
     * @param array<string, Schema> $schemas
     * @return array<string, array<string, callable>>
     */
    private static function lifecycles(array $schemas): array
    {
        $lifecycles = [];
        foreach ($schemas as $schema) {
            if (($schema->config['lifecycles'] ?? []) !== []) {
                $lifecycles[$schema->uid] = $schema->config['lifecycles'];
            }
        }

        return $lifecycles;
    }

    public function testAnEntryLoadsTheSameMetadataWithItsCallablesAndLifecycles(): void
    {
        $beforeCreate = static function (): void {};
        $schemas = self::schemasWithLifecycles($beforeCreate);
        $built = Metadata::create(SchemaFactory::toModels($schemas));

        $entry = $built->toCache();
        self::assertNotNull($entry);
        self::assertSame($entry, self::roundTrip($entry), 'plain data: no object left');

        $loaded = new Metadata();
        self::assertTrue($loaded->loadCache(self::roundTrip($entry), self::lifecycles($schemas)));

        self::assertSame($built->keys(), $loaded->keys());
        self::assertSame($entry, $loaded->toCache(), 'the same metadata, join tables included');

        $thing = $loaded->get('api::thing.thing');
        self::assertSame(['beforeCreate' => $beforeCreate], $thing['lifecycles']);
        self::assertSame(SchemaFactory::callable(SchemaFactory::DOCUMENT_ID), $thing['attributes']['documentId']['default']);
        self::assertSame(SchemaFactory::callable(SchemaFactory::NOW), $thing['attributes']['publishedAt']['default']);
        self::assertSame(SchemaFactory::callable(SchemaFactory::NOT_IN_RESULTS), $thing['attributes']['localizations']['joinColumn']['on']);
        self::assertEquals(new \stdClass(), $thing['attributes']['data']['default']);
        self::assertSame($built->get('api::thing.thing')['columnToAttribute'], $thing['columnToAttribute']);
    }

    public function testAnotherClosureIsNotCached(): void
    {
        // a model's own default function cannot be named: such metadata is built at every boot
        $metadata = Metadata::create([[
            'uid' => 'plugin::x.job',
            'singularName' => 'job',
            'tableName' => 'jobs',
            'attributes' => ['createdAt' => ['type' => 'datetime', 'default' => static fn (): string => 'now']],
        ]]);

        self::assertNull($metadata->toCache());
    }

    public function testTheIdentifiersShortenedNamesComeBackAndAnotherLengthIsAMiss(): void
    {
        Identifiers::setGlobal(new Identifiers(['maxLength' => 20]));
        $models = [[
            'uid' => 'api::a-rather-long-model-name.a-rather-long-model-name',
            'singularName' => 'a-rather-long-model-name',
            'tableName' => 'a_rather_long_model_names_table',
            'attributes' => ['a_rather_long_attribute_name' => ['type' => 'string']],
        ]];
        $built = Metadata::create($models);
        $entry = self::roundTrip((array) $built->toCache());
        $shortened = Identifiers::global()->nameMap;
        self::assertNotSame([], $shortened);

        Identifiers::setGlobal(new Identifiers(['maxLength' => 20]));
        $loaded = new Metadata();
        self::assertTrue($loaded->loadCache($entry, []));
        self::assertSame($shortened, Identifiers::global()->nameMap);
        self::assertSame($built->get($models[0]['uid'])['tableName'], $loaded->get($models[0]['uid'])['tableName']);

        Identifiers::setGlobal(new Identifiers(['maxLength' => 63]));
        $other = new Metadata();
        self::assertFalse($other->loadCache($entry, []));
        self::assertSame([], $other->keys(), 'nothing loaded');
    }

    public function testADatabaseInitializedFromAnEntryQueriesAsOneInitializedFromTheModels(): void
    {
        $created = [];
        $schemas = self::schemasWithLifecycles(static function () use (&$created): void {
            $created[] = true;
        });
        $entry = self::roundTrip((array) GetstartedDatabase::create([], $schemas)->metadata->toCache());

        $db = new Database([
            'connection' => ['client' => 'sqlite', 'connection' => ['filename' => ':memory:'], 'useNullAsDefault' => true],
            'settings' => ['forceMigration' => true, 'runMigrations' => true, 'migrations' => ['dir' => '']],
        ]);
        self::assertTrue($db->initFromCache($entry, self::lifecycles($schemas)));
        $db->schema->sync();

        $thing = $db->query('api::thing.thing')->create(['data' => ['name' => 'one', 'locale' => 'en']]);
        self::assertIsString($thing['documentId'], 'the documentId default ran');
        self::assertSame(24, strlen($thing['documentId']));
        self::assertNotNull($thing['publishedAt']);
        self::assertSame([true], $created, 'the lifecycle ran');

        $db->query('api::thing.thing')->create(['data' => ['name' => 'un', 'locale' => 'fr', 'documentId' => $thing['documentId']]]);
        $found = $db->query('api::thing.thing')->findOne(['where' => ['id' => $thing['id']], 'populate' => ['localizations' => true]]);
        self::assertIsArray($found);
        self::assertSame(['fr'], array_column($found['localizations'], 'locale'), 'the join condition leaves the row itself out');

        $db->destroy();
    }
}
