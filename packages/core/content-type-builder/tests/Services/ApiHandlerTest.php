<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\ApiHandler;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;

require_once __DIR__ . '/../StubStrapi.php';

/** Port of server/src/services/__tests__/api-handler.test.ts. */
final class ApiHandlerTest extends TestCase
{
    private const string UID = 'api::article.article';

    private string $appRoot;

    private ApiHandler $apiHandler;

    protected function setUp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/ctb-api-handler-' . bin2hex(random_bytes(6));
        mkdir($this->appRoot . '/src/api', 0777, true);

        $strapi = StubStrapi::create($this->appRoot);
        StubStrapi::addContentTypes($strapi, [self::UID => ['apiName' => 'article', 'modelName' => 'article']]);
        $this->apiHandler = new ApiHandler($strapi);

        self::outputFile($this->apiFolder() . '/controllers/article.ts', 'export default {}');
        self::outputFile($this->apiFolder() . '/content-types/article/schema.json', '{"kind":"collectionType"}');
    }

    protected function tearDown(): void
    {
        SchemaHandler::remove($this->appRoot);
    }

    private static function outputFile(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function apiFolder(): string
    {
        return $this->appRoot . '/src/api/article';
    }

    private function backupFolder(): string
    {
        return $this->appRoot . '/src/api/.backup/article';
    }

    public function testKeepsAnApiBackupUntilCommitThenRestoresTheExactFiles(): void
    {
        $this->apiHandler->backup(self::UID);
        $this->apiHandler->clear(self::UID, ['preserveBackup' => true]);

        self::assertFileDoesNotExist($this->apiFolder());
        self::assertSame('export default {}', file_get_contents($this->backupFolder() . '/controllers/article.ts'));

        $this->apiHandler->rollback(self::UID);

        self::assertSame('export default {}', file_get_contents($this->apiFolder() . '/controllers/article.ts'));
        self::assertSame(['kind' => 'collectionType'], json_decode((string) file_get_contents($this->apiFolder() . '/content-types/article/schema.json'), true));
        self::assertFileDoesNotExist($this->backupFolder());
    }

    public function testRemovesThePreservedBackupAfterACommittedApiDeletion(): void
    {
        $this->apiHandler->backup(self::UID);
        $this->apiHandler->clear(self::UID, ['preserveBackup' => true]);
        $this->apiHandler->finalize(self::UID);

        self::assertFileDoesNotExist($this->apiFolder());
        self::assertFileDoesNotExist($this->backupFolder());
    }

    public function testReplacesRetainedPartialBackupFilesBeforeALaterRollback(): void
    {
        // Simulates a previous partial/retained backup attempt that must never merge into a retry.
        self::outputFile($this->backupFolder() . '/controllers/stale.ts', 'stale');
        self::outputFile($this->apiFolder() . '/services/article.ts', 'export default {}');

        $this->apiHandler->backup(self::UID);
        $this->apiHandler->clear(self::UID, ['preserveBackup' => true]);
        $this->apiHandler->rollback(self::UID);

        self::assertFileDoesNotExist($this->apiFolder() . '/controllers/stale.ts');
        self::assertSame('export default {}', file_get_contents($this->apiFolder() . '/controllers/article.ts'));
        self::assertSame('export default {}', file_get_contents($this->apiFolder() . '/services/article.ts'));
        self::assertFileDoesNotExist($this->backupFolder());
    }

    public function testCleansAFailedStagingCopyWithoutAlteringTheRetainedCanonicalBackup(): void
    {
        $stagingFolder = $this->appRoot . '/src/api/.backup/.article.staging';
        self::outputFile($this->backupFolder() . '/controllers/retained.ts', 'retained');
        self::outputFile($stagingFolder . '/controllers/partial.ts', 'partial');
        SchemaHandler::remove($this->apiFolder());

        try {
            $this->apiHandler->backup(self::UID);
            self::fail('Expected backup() to throw');
        } catch (\RuntimeException) {
        }

        self::assertFileDoesNotExist($stagingFolder);
        self::assertSame('retained', file_get_contents($this->backupFolder() . '/controllers/retained.ts'));
    }
}
