<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\ApiHandler;
use Strapi\ContentTypeBuilder\Services\ContentTypes;
use Strapi\ContentTypeBuilder\Tests\TempApp;
use Strapi\Utils\Errors\ApplicationError;

require_once __DIR__ . '/../TempApp.php';

/**
 * Port of server/src/services/__tests__/content-types-mutations.test.ts.
 *
 * Upstream replaces the schema builder, the API handler and `@strapi/generators` with in-memory
 * mocks. Here the services run for real against a scratch project ({@see TempApp}); failures are
 * provoked on disk (a path blocked by a file, an existing generated file, a missing API folder)
 * or through stub `api-handler` / `content-structure` services, and the assertions check the
 * files the compensation leaves behind.
 */
final class ContentTypesMutationsTest extends TestCase
{
    private TempApp $app;

    /** @var list<string> */
    private array $initialFiles;

    private string $groups = 'before';

    protected function setUp(): void
    {
        $this->app = new TempApp();
        $this->groups = 'before';

        $this->app->addApiContentType('article', [
            'kind' => 'singleType',
            'collectionName' => 'articles',
            'info' => ['singularName' => 'article', 'pluralName' => 'articles', 'displayName' => 'Article'],
            'options' => [],
            'attributes' => [],
        ]);
        $this->app->addApiContentType('category', [
            'kind' => 'collectionType',
            'collectionName' => 'categories',
            'info' => ['singularName' => 'category', 'pluralName' => 'categories', 'displayName' => 'Category'],
            'options' => [],
            'attributes' => [],
        ]);
        $this->app->addPluginContentType('example', 'article', [
            'kind' => 'singleType',
            'collectionName' => 'example_articles',
            'info' => ['singularName' => 'article', 'pluralName' => 'articles', 'displayName' => 'Article'],
            'options' => [],
            'attributes' => [],
        ]);

        $this->initialFiles = $this->app->files();
        $this->setContentStructure(null);
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    /** A content-structure service whose commit fails with `$failure`, or records the write. */
    private function setContentStructure(?string $failure): void
    {
        $this->app->setService('content-structure', new class ($failure, $this->groups) {
            public function __construct(private ?string $failure, private string &$groups)
            {
            }

            /** @param array<string, mixed> $input */
            public function commitFromUpdate(array $input): bool
            {
                if ($this->failure !== null) {
                    throw new \RuntimeException($this->failure);
                }
                $this->groups = 'after';

                return true;
            }
        });
    }

    private function service(): ContentTypes
    {
        return new ContentTypes($this->app->strapi);
    }

    /** @return array<string, mixed> */
    private static function createInput(string $name = 'post', array $components = []): array
    {
        return [
            'contentType' => [
                'displayName' => ucfirst($name),
                'singularName' => $name,
                'pluralName' => "{$name}s",
                'kind' => 'collectionType',
                'attributes' => [],
            ],
            'components' => $components,
        ];
    }

    /** A new component whose directory cannot be created: the schema write fails. */
    private function blockedComponent(): array
    {
        $this->app->write('src/components/blocked', 'a file where the category directory should be');
        $this->initialFiles = $this->app->files();

        return [['tmpUID' => 'blocked.thing', 'category' => 'blocked', 'displayName' => 'thing', 'attributes' => []]];
    }

    private static function catch(\Closure $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            return $error;
        }

        self::fail('Expected an exception');
    }

    public function testRemovesAGeneratedApiSkeletonWhenStandaloneSchemaCreationFails(): void
    {
        $components = $this->blockedComponent();

        $error = self::catch(fn () => $this->service()->createContentType(self::createInput('post', $components)));

        self::assertInstanceOf(ApplicationError::class, $error);
        self::assertSame('Invalid schema edition', $error->getMessage());
        self::assertSame($this->initialFiles, $this->app->files());
        self::assertSame('before', $this->groups);
    }

    public function testRemovesAPartialSkeletonWhenGenerationFails(): void
    {
        // the generator refuses to overwrite a file: it fails after writing part of the API
        $this->app->write('src/api/post/routes/post.php', '<?php // leftover');

        $error = self::catch(fn () => $this->service()->createContentType(self::createInput()));

        self::assertStringContainsString('File already exists', $error->getMessage());
        self::assertFileDoesNotExist($this->app->path('src/api/post'));
        self::assertSame('before', $this->groups);
    }

    public function testRemovesEveryGeneratedSkeletonAndDefersCreateEventsWhenABatchSchemaWriteFails(): void
    {
        $components = $this->blockedComponent();

        $error = self::catch(fn () => $this->service()->createContentTypes([
            self::createInput('post'),
            self::createInput('page', $components),
        ]));

        self::assertSame('Invalid schema edition', $error->getMessage());
        self::assertSame($this->initialFiles, $this->app->files());
        self::assertSame([], $this->app->events);
    }

    public function testRestoresSchemaAndApiFilesWhenAKindSwitchCannotReconcileFolders(): void
    {
        $schemaBefore = $this->app->read('src/api/article/content-types/article/schema.json');
        $this->setContentStructure('groups write failed');

        $error = self::catch(fn () => $this->service()->editContentType('api::article.article', [
            'contentType' => ['displayName' => 'Article', 'kind' => 'collectionType', 'attributes' => []],
        ]));

        self::assertSame('groups write failed', $error->getMessage());
        self::assertSame($this->initialFiles, $this->app->files());
        self::assertSame($schemaBefore, $this->app->read('src/api/article/content-types/article/schema.json'));
        self::assertSame('before', $this->groups);
    }

    public function testRejectsACraftedProtectedPluginDeletionBeforeItMutatesAnything(): void
    {
        $error = self::catch(fn () => $this->service()->deleteContentType('plugin::example.article'));

        self::assertMatchesRegularExpression('/not managed by CTB/', $error->getMessage());
        self::assertSame($this->initialFiles, $this->app->files());
        self::assertSame('before', $this->groups);
    }

    public function testDoesNotGenerateAnAppApiSkeletonWhileSwitchingAPluginExtensionKind(): void
    {
        $this->service()->editContentType('plugin::example.article', [
            'contentType' => ['displayName' => 'Article', 'kind' => 'collectionType', 'attributes' => []],
        ]);

        $apiFiles = array_values(array_filter($this->app->files(), static fn (string $f): bool => str_starts_with($f, 'src/api/')));
        self::assertSame(array_values(array_filter($this->initialFiles, static fn (string $f): bool => str_starts_with($f, 'src/api/'))), $apiFiles);
        // the plugin extension schema is written under src/extensions
        self::assertSame('collectionType', json_decode((string) $this->app->read('src/extensions/example/content-types/article/schema.json'), true)['kind']);
        self::assertSame('after', $this->groups);
    }

    public function testRestoresAllSchemasAndApisWhenBulkFolderReconciliationFails(): void
    {
        $this->setContentStructure('groups write failed');

        $error = self::catch(fn () => $this->service()->deleteContentTypes(['api::article.article', 'api::category.category']));

        self::assertSame('groups write failed', $error->getMessage());
        self::assertSame($this->initialFiles, $this->app->files());
        self::assertSame('before', $this->groups);
    }

    public function testOnlyRestoresBackupsThatCompletedWhenBulkBackupFailsPartway(): void
    {
        // the second API folder is gone: its backup fails
        \Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler::remove($this->app->path('src/api/category'));
        $this->initialFiles = $this->app->files();

        $rolledBack = new \ArrayObject();
        $real = new ApiHandler($this->app->strapi);
        $this->app->setService('api-handler', new class ($real, $rolledBack) {
            /** @param \ArrayObject<int, string> $rolledBack */
            public function __construct(private ApiHandler $real, private \ArrayObject $rolledBack)
            {
            }

            public function __call(string $name, array $args): mixed
            {
                if ($name === 'rollback') {
                    $this->rolledBack[] = $args[0];
                }

                return $this->real->{$name}(...$args);
            }
        });

        $error = self::catch(fn () => $this->service()->deleteContentTypes(['api::article.article', 'api::category.category']));

        self::assertStringContainsString('ENOENT', $error->getMessage());
        self::assertSame($this->initialFiles, $this->app->files());
        self::assertSame(['api::article.article'], $rolledBack->getArrayCopy());
        self::assertSame('before', $this->groups);
    }

    public function testReportsACommittedDeletionAndEmitsItsEventWhenBackupCleanupAloneFails(): void
    {
        $real = new ApiHandler($this->app->strapi);
        $this->app->setService('api-handler', new class ($real) {
            public function __construct(private ApiHandler $real)
            {
            }

            public function __call(string $name, array $args): mixed
            {
                if ($name === 'finalize') {
                    throw new \RuntimeException('backup cleanup failed');
                }

                return $this->real->{$name}(...$args);
            }
        });

        $this->service()->deleteContentTypes(['api::article.article']);

        self::assertFileDoesNotExist($this->app->path('src/api/article/content-types/article/schema.json'));
        self::assertFileDoesNotExist($this->app->path('src/api/article/controllers/article.php'));
        self::assertFileExists($this->app->path('src/api/category/content-types/category/schema.json'));
        self::assertSame('after', $this->groups);
        self::assertCount(1, $this->app->events);
        self::assertSame('content-type.delete', $this->app->events[0][0]);
        self::assertSame('api::article.article', $this->app->events[0][1]['contentType']->uid());
    }
}
