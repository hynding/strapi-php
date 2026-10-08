<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\ApiHandler;
use Strapi\ContentTypeBuilder\Services\Schema;
use Strapi\ContentTypeBuilder\Tests\TempApp;
use Strapi\Utils\Errors\ApplicationError;

require_once __DIR__ . '/../TempApp.php';

/**
 * Port of server/src/services/__tests__/schema.test.ts.
 *
 * Upstream checks the calls `updateSchema()` makes on a mocked schema builder; here the real
 * builder runs against a scratch project ({@see TempApp}) and the tests check the files it writes,
 * the events it emits and the calls it makes on stub `content-structure` / `api-handler` services.
 */
final class SchemaTest extends TestCase
{
    private TempApp $app;

    /** @var list<array{string, array<string, mixed>}> */
    private array $structureCalls = [];

    private ?string $validateFailure = null;

    private ?string $commitFailure = null;

    protected function setUp(): void
    {
        $this->app = new TempApp();
        $this->structureCalls = [];
        $this->validateFailure = null;
        $this->commitFailure = null;

        $this->app->addApiContentType('test', [
            'kind' => 'collectionType',
            'collectionName' => 'tests',
            'info' => ['singularName' => 'test', 'pluralName' => 'tests', 'displayName' => 'Test'],
            'options' => [],
            'attributes' => [
                'title' => ['type' => 'string'],
                'counter' => ['type' => 'integer'],
                'published' => ['type' => 'boolean'],
            ],
        ]);
        $this->app->addComponent('default', 'card', [
            'collectionName' => 'components_default_cards',
            'info' => ['displayName' => 'card'],
            'options' => [],
            'attributes' => ['name' => ['type' => 'string'], 'subtitle' => ['type' => 'string']],
        ]);

        $test = $this;
        $this->app->setService('content-structure', new class ($test) {
            public function __construct(private SchemaTest $test)
            {
            }

            /** @param array<string, mixed> $input */
            public function validateFromUpdate(array $input): void
            {
                $this->test->recordStructureCall('validate', $input);
            }

            /** @param array<string, mixed> $input */
            public function commitFromUpdate(array $input): bool
            {
                $this->test->recordStructureCall('commit', $input);

                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    /** @param array<string, mixed> $input */
    public function recordStructureCall(string $step, array $input): void
    {
        $this->structureCalls[] = [$step, $input];
        $failure = $step === 'validate' ? $this->validateFailure : $this->commitFailure;
        if ($failure !== null) {
            throw new ApplicationError($failure);
        }
    }

    private function service(): Schema
    {
        return new Schema($this->app->strapi);
    }

    /** @return array<string, mixed>|null */
    private function json(string $relative): ?array
    {
        $contents = $this->app->read($relative);

        return $contents === null ? null : json_decode($contents, true);
    }

    /** @return list<string> */
    private function eventNames(): array
    {
        return array_map(static fn (array $e): string => $e[0], $this->app->events);
    }

    /**
     * @param list<array<string, mixed>> $contentTypes
     * @param list<array<string, mixed>> $components
     */
    private static function schema(array $contentTypes = [], array $components = [], mixed $contentStructure = null): array
    {
        $schema = ['contentTypes' => $contentTypes, 'components' => $components];
        if ($contentStructure !== null) {
            $schema['contentStructure'] = $contentStructure;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function createArticle(): array
    {
        return [
            'action' => 'create',
            'uid' => 'api::article.article',
            'displayName' => 'Article',
            'singularName' => 'article',
            'pluralName' => 'articles',
            'kind' => 'collectionType',
            'draftAndPublish' => false,
            'pluginOptions' => [],
            'options' => [],
            'attributes' => [['action' => 'create', 'name' => 'title', 'properties' => ['type' => 'string']]],
        ];
    }

    private static function emptyStructure(): array
    {
        return ['version' => 1, 'sections' => ['collectionTypes' => ['groups' => []], 'singleTypes' => ['groups' => []]]];
    }

    public function testHandlesContentTypeCreationAndEmitsAnEvent(): void
    {
        $this->service()->updateSchema(self::schema([self::createArticle()]));

        self::assertSame(<<<'JSON'
            {
              "kind": "collectionType",
              "collectionName": "articles",
              "info": {
                "singularName": "article",
                "pluralName": "articles",
                "displayName": "Article"
              },
              "options": {
                "draftAndPublish": false
              },
              "pluginOptions": {},
              "attributes": {
                "title": {
                  "type": "string"
                }
              }
            }

            JSON, $this->app->read('src/api/article/content-types/article/schema.json'));
        self::assertNotNull($this->app->read('src/api/article/controllers/article.php'));
        self::assertNotNull($this->app->read('src/api/article/services/article.php'));
        self::assertNotNull($this->app->read('src/api/article/routes/article.php'));
        self::assertSame(['content-type.create'], $this->eventNames());
        self::assertSame('api::article.article', $this->app->events[0][1]['contentType']->uid());
    }

    public function testHandlesContentTypeUpdateAndEmitsAnEvent(): void
    {
        $this->service()->updateSchema(self::schema([[
            'action' => 'update',
            'uid' => 'api::test.test',
            'displayName' => 'Updated Test',
            'kind' => 'collectionType',
            'draftAndPublish' => false,
            'pluginOptions' => [],
            'options' => [],
            'attributes' => [
                ['action' => 'update', 'name' => 'title', 'properties' => ['type' => 'string']],
                ['action' => 'update', 'name' => 'counter', 'properties' => ['type' => 'integer']],
                ['action' => 'update', 'name' => 'published', 'properties' => ['type' => 'boolean']],
                ['action' => 'create', 'name' => 'description', 'properties' => ['type' => 'text']],
            ],
        ]]));

        $schema = $this->json('src/api/test/content-types/test/schema.json');
        self::assertSame('Updated Test', $schema['info']['displayName']);
        self::assertSame(['title', 'counter', 'published', 'description'], array_keys($schema['attributes']));
        self::assertSame(['content-type.update'], $this->eventNames());
    }

    public function testHandlesContentTypeDeletionBackupClearApiAndEmitsAnEvent(): void
    {
        $this->service()->updateSchema(self::schema([['action' => 'delete', 'uid' => 'api::test.test']]));

        self::assertNull($this->app->read('src/api/test/content-types/test/schema.json'));
        self::assertNull($this->app->read('src/api/test/controllers/test.php'));
        self::assertFileDoesNotExist($this->app->path('src/api/.backup'));
        self::assertSame(['content-type.delete'], $this->eventNames());
        self::assertSame(['api::test.test'], $this->structureCalls[0][1]['deletedUids']);
    }

    public function testRejectsACraftedProtectedPluginDeleteBeforeAnyMutation(): void
    {
        $files = $this->app->files();

        try {
            $this->service()->updateSchema(self::schema([['action' => 'delete', 'uid' => 'plugin::example.article']]));
            self::fail('Expected an ApplicationError');
        } catch (ApplicationError $error) {
            self::assertMatchesRegularExpression('/not managed by CTB/', $error->getMessage());
        }

        self::assertSame($files, $this->app->files());
        self::assertSame([], $this->structureCalls);
    }

    public function testHandlesComponentCreationAndEmitsAnEvent(): void
    {
        $this->service()->updateSchema(self::schema([], [[
            'action' => 'create',
            'uid' => 'default.hero',
            'displayName' => 'hero',
            'category' => 'default',
            'config' => [],
            'attributes' => [['action' => 'create', 'name' => 'title', 'properties' => ['type' => 'string']]],
        ]]));

        self::assertSame(<<<'JSON'
            {
              "collectionName": "components_default_heroes",
              "info": {
                "displayName": "hero"
              },
              "options": {},
              "attributes": {
                "title": {
                  "type": "string"
                }
              },
              "config": {}
            }

            JSON, $this->app->read('src/components/default/hero.json'));
        self::assertSame(['component.create'], $this->eventNames());
    }

    public function testHandlesComponentUpdateWithAttributeDeletionAndEmitsAnEvent(): void
    {
        $this->service()->updateSchema(self::schema([], [[
            'action' => 'update',
            'uid' => 'default.card',
            'displayName' => 'Card',
            'category' => 'default',
            'attributes' => [
                ['action' => 'update', 'name' => 'name', 'properties' => ['type' => 'string', 'required' => true]],
                ['action' => 'delete', 'name' => 'subtitle'],
            ],
        ]]));

        $component = $this->json('src/components/default/card.json');
        self::assertSame('Card', $component['info']['displayName']);
        self::assertSame(['name' => ['type' => 'string', 'required' => true]], $component['attributes']);
        self::assertSame(['component.update'], $this->eventNames());
    }

    public function testHandlesComponentDeletionAndEmitsAnEvent(): void
    {
        $this->service()->updateSchema(self::schema([], [['action' => 'delete', 'uid' => 'default.card']]));

        self::assertNull($this->app->read('src/components/default/card.json'));
        self::assertSame(['component.delete'], $this->eventNames());
    }

    public function testRollsBackWhenApiClearingFails(): void
    {
        $files = $this->app->files();
        $schemaBefore = $this->app->read('src/api/test/content-types/test/schema.json');
        $real = new ApiHandler($this->app->strapi);
        $this->app->setService('api-handler', new class ($real) {
            public function __construct(private ApiHandler $real)
            {
            }

            public function __call(string $name, array $args): mixed
            {
                if ($name === 'clear') {
                    throw new \RuntimeException('Failed to clear API');
                }

                return $this->real->{$name}(...$args);
            }
        });

        try {
            $this->service()->updateSchema(self::schema([['action' => 'delete', 'uid' => 'api::test.test']]));
            self::fail('Expected the clear error');
        } catch (\RuntimeException $error) {
            self::assertSame('Failed to clear API', $error->getMessage());
        }

        self::assertSame($files, $this->app->files());
        self::assertSame($schemaBefore, $this->app->read('src/api/test/content-types/test/schema.json'));
        self::assertSame([], $this->app->events);
    }

    public function testHandlesMixedAttributeOperationsDuringUpdate(): void
    {
        $this->service()->updateSchema(self::schema([[
            'action' => 'update',
            'uid' => 'api::test.test',
            'displayName' => 'Test',
            'kind' => 'collectionType',
            'draftAndPublish' => false,
            'pluginOptions' => [],
            'options' => [],
            'attributes' => [
                ['name' => 'counter', 'action' => 'delete'],
                ['action' => 'update', 'name' => 'title', 'properties' => ['type' => 'string', 'required' => true, 'maxLength' => 100]],
                ['action' => 'create', 'name' => 'description', 'properties' => ['type' => 'text']],
                ['action' => 'update', 'name' => 'published', 'properties' => ['type' => 'boolean']],
            ],
        ]]));

        self::assertSame([
            'title' => ['type' => 'string', 'required' => true, 'maxLength' => 100],
            'description' => ['type' => 'text'],
            'published' => ['type' => 'boolean'],
        ], $this->json('src/api/test/content-types/test/schema.json')['attributes']);
        self::assertSame(['content-type.update'], $this->eventNames());
    }

    public function testForwardsAKindChangedOnUpdateToTheFolderSteps(): void
    {
        $contentStructure = [
            'version' => 1,
            'sections' => [
                'collectionTypes' => ['groups' => []],
                'singleTypes' => ['groups' => [['id' => 'grp_s', 'name' => 'S', 'parent' => null, 'children' => [['type' => 'contentType', 'uid' => 'api::test.test']]]]],
            ],
        ];

        $this->service()->updateSchema(self::schema([[
            'action' => 'update',
            'uid' => 'api::test.test',
            'displayName' => 'Test',
            'kind' => 'singleType',
            'draftAndPublish' => false,
            'pluginOptions' => [],
            'options' => [],
            'attributes' => [],
        ]], [], $contentStructure));

        self::assertSame([
            ['validate', ['incomingStructure' => $contentStructure, 'upsertedUids' => ['api::test.test' => 'singleType'], 'deletedUids' => []]],
            ['commit', ['incomingStructure' => $contentStructure, 'deletedUids' => []]],
        ], $this->structureCalls);
    }

    public function testValidatesFolderReferencesBeforeScaffoldingAndCommitsAfterWriting(): void
    {
        $test = $this;
        $this->app->setService('content-structure', new class ($test) {
            public function __construct(private SchemaTest $test)
            {
            }

            /** @param array<string, mixed> $input */
            public function validateFromUpdate(array $input): void
            {
                // nothing is scaffolded yet
                TestCase::assertFileDoesNotExist($this->test->appPath('src/api/article'));
            }

            /** @param array<string, mixed> $input */
            public function commitFromUpdate(array $input): bool
            {
                // the schema files are written
                TestCase::assertFileExists($this->test->appPath('src/api/article/content-types/article/schema.json'));

                return true;
            }
        });

        $this->service()->updateSchema(self::schema([self::createArticle()], [], self::emptyStructure()));
        $this->addToAssertionCount(1);
    }

    public function appPath(string $relative): string
    {
        return $this->app->path($relative);
    }

    public function testDoesNotCommitTheFolderFileWhenFolderValidationRejectsThePayload(): void
    {
        $files = $this->app->files();
        $this->validateFailure = 'Invalid content structure';

        try {
            $this->service()->updateSchema(self::schema([self::createArticle()], [], self::emptyStructure()));
            self::fail('Expected the validation error');
        } catch (ApplicationError $error) {
            self::assertSame('Invalid content structure', $error->getMessage());
        }

        self::assertSame(['validate'], array_column($this->structureCalls, 0));
        self::assertSame($files, $this->app->files());
        self::assertSame([], $this->app->events);
    }

    public function testRollsBackTheSchemaFilesWhenTheFolderCommitFails(): void
    {
        $files = $this->app->files();
        $this->commitFailure = 'groups write failed';

        try {
            $this->service()->updateSchema(self::schema([self::createArticle(), ['action' => 'delete', 'uid' => 'api::test.test']], [], self::emptyStructure()));
            self::fail('Expected the commit error');
        } catch (ApplicationError $error) {
            self::assertSame('groups write failed', $error->getMessage());
        }

        self::assertSame($files, $this->app->files());
        self::assertSame([], $this->app->events);
    }

    public function testDoesNotCommitTheFolderFileWhenWriteFilesRollsTheSchemaBack(): void
    {
        // the component category directory is blocked by a file: writing the schema fails
        $this->app->write('src/components/blocked', 'x');
        $files = $this->app->files();

        try {
            $this->service()->updateSchema(self::schema([self::createArticle()], [[
                'action' => 'create',
                'uid' => 'blocked.thing',
                'displayName' => 'thing',
                'category' => 'blocked',
                'config' => [],
                'attributes' => [],
            ]], self::emptyStructure()));
            self::fail('Expected the write error');
        } catch (ApplicationError $error) {
            self::assertSame('Invalid schema edition', $error->getMessage());
        }

        self::assertSame(['validate'], array_column($this->structureCalls, 0));
        self::assertSame($files, $this->app->files());
    }

    public function testRemovesAPartialGeneratedApiWhenGenerationFails(): void
    {
        $this->app->write('src/api/article/routes/article.php', '<?php // leftover');

        try {
            $this->service()->updateSchema(self::schema([self::createArticle()]));
            self::fail('Expected the generator error');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('File already exists', $error->getMessage());
        }

        self::assertFileDoesNotExist($this->app->path('src/api/article'));
    }

    public function testGetSchemaReturnsTheFolderStructureFromTheCoreService(): void
    {
        $file = self::emptyStructure();
        $calls = 0;
        $this->app->strapi->set('content-structure', new class ($file, $calls) {
            /** @param array<string, mixed> $file */
            public function __construct(private array $file, private int &$calls)
            {
            }

            /** @return array<string, mixed> */
            public function getCleanedFile(): array
            {
                $this->calls++;

                return $this->file;
            }
        });

        $result = $this->service()->getSchema();

        self::assertSame($file, $result['contentStructure']);
        self::assertSame(1, $calls);
        self::assertArrayHasKey('api::test.test', $result['contentTypes']);
        self::assertSame(['title', 'counter', 'published'], array_column($result['contentTypes']['api::test.test']['attributes'], 'name'));
        self::assertArrayHasKey('default.card', $result['components']);
    }
}
