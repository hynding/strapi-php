<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services\SchemaBuilder;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;

/**
 * Port of server/src/services/schema-builder/__tests__/schema-handler.vitest.test.ts, writing to a
 * scratch directory instead of mocking fs-extra, plus the byte format of the written files.
 */
final class SchemaHandlerTest extends TestCase
{
    private const array INDEXES = [['name' => 'tests_slug_custom_idx', 'columns' => ['slug'], 'type' => null]];
    private const array FOREIGN_KEYS = [['name' => 'tests_author_fk', 'columns' => ['author_id']]];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ctb-schema-handler-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        SchemaHandler::remove($this->dir);
    }

    /** @param array<string, mixed> $overrides */
    private function handler(array $overrides = []): SchemaHandler
    {
        return SchemaHandler::createSchemaHandler([
            'uid' => 'api::test.test',
            'dir' => $this->dir . '/api/test/content-types/test',
            'filename' => 'schema.json',
            'schema' => [
                'kind' => 'collectionType',
                'collectionName' => 'tests',
                'info' => ['singularName' => 'test', 'pluralName' => 'tests', 'displayName' => 'test'],
                'options' => ['draftAndPublish' => true],
                'pluginOptions' => [],
                'attributes' => [
                    'test' => ['type' => 'string'],
                    'slug' => ['type' => 'uid', 'targetField' => 'test'],
                ],
                ...$overrides,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function written(): array
    {
        return json_decode((string) file_get_contents($this->dir . '/api/test/content-types/test/schema.json'), true);
    }

    public function testWritesExperimentalIndexesAndForeignKeysWhenSavingAnUnrelatedChange(): void
    {
        $handler = $this->handler(['indexes' => self::INDEXES, 'foreignKeys' => self::FOREIGN_KEYS]);

        $handler->setAttribute('extra', ['type' => 'string']);
        $handler->flush();

        $written = $this->written();
        self::assertSame('tests', $written['collectionName']);
        self::assertSame(['type' => 'string'], $written['attributes']['extra']);
        self::assertSame(self::INDEXES, $written['indexes']);
        self::assertSame(self::FOREIGN_KEYS, $written['foreignKeys']);
    }

    public function testDoesNotInventIndexesOrForeignKeysWhenTheSchemaHasNone(): void
    {
        $handler = $this->handler();

        $handler->setAttribute('extra', ['type' => 'string']);
        $handler->flush();

        self::assertArrayNotHasKey('indexes', $this->written());
        self::assertArrayNotHasKey('foreignKeys', $this->written());
    }

    public function testToleratesAnAlreadyRemovedDirectoryWhileRollingBackANewlyCreatedSchema(): void
    {
        $handler = SchemaHandler::createSchemaHandler([
            'dir' => $this->dir . '/api/new/content-types/new',
            'filename' => 'schema.json',
        ]);
        $handler->setUID('api::new.new');

        $handler->rollback();

        self::assertDirectoryDoesNotExist($this->dir . '/api/new');
    }

    public function testWritesFilesAsJsonStringifyWithTwoSpaces(): void
    {
        $handler = $this->handler();
        $handler->setAttribute('extra', ['type' => 'string', 'pluginOptions' => [], 'default' => 'a/é']);
        $handler->flush();

        self::assertSame(<<<'JSON'
            {
              "kind": "collectionType",
              "collectionName": "tests",
              "info": {
                "singularName": "test",
                "pluralName": "tests",
                "displayName": "test"
              },
              "options": {
                "draftAndPublish": true
              },
              "pluginOptions": {},
              "attributes": {
                "test": {
                  "type": "string"
                },
                "slug": {
                  "type": "uid",
                  "targetField": "test"
                },
                "extra": {
                  "type": "string",
                  "pluginOptions": {},
                  "default": "a/é"
                }
              }
            }

            JSON, (string) file_get_contents($this->dir . '/api/test/content-types/test/schema.json'));
    }

    public function testSetWithoutAValueKeepsTheCurrentOne(): void
    {
        $handler = $this->handler();
        $handler->set(['info', 'displayName'], null)->set(['info', 'description'], null);

        self::assertSame(['singularName' => 'test', 'pluralName' => 'tests', 'displayName' => 'test'], $handler->schema()['info']);
    }
}
