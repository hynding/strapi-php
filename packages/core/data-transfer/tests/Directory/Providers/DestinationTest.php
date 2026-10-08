<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Directory\Providers;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Directory\Providers\Destination\Destination;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Tests\TempDir;
use Strapi\DataTransfer\Utils\Diagnostic;

/** Port of src/directory/providers/destination/__tests__/index.test.ts */
final class DestinationTest extends TestCase
{
    use TempDir;

    public function testBootstrapCreatesRootAndSetsResultsFile(): void
    {
        $dir = $this->tempDir() . '/export';
        $provider = Destination::createLocalDirectoryDestinationProvider(['directory' => ['path' => $dir], 'file' => []]);

        $provider->bootstrap(Diagnostic::createDiagnosticReporter());

        self::assertDirectoryExists($dir);
        self::assertSame($dir, $provider->results['file']['path'] ?? null);
    }

    public function testCloseDoesNotRecreateAnExportAfterRollback(): void
    {
        $dir = $this->tempDir() . '/export';
        $provider = Destination::createLocalDirectoryDestinationProvider(['directory' => ['path' => $dir], 'file' => []]);
        $provider->setMetadata('source', ['createdAt' => gmdate('c'), 'strapi' => ['version' => '5.0.0']]);

        $provider->bootstrap(Diagnostic::createDiagnosticReporter());
        $provider->rollback();
        $provider->close();

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testCreateAssetsWriteStreamSurfacesFsErrorsAsProviderTransferError(): void
    {
        $dir = $this->tempDir() . '/export';
        $provider = Destination::createLocalDirectoryDestinationProvider(['directory' => ['path' => $dir], 'file' => []]);
        $provider->bootstrap(Diagnostic::createDiagnosticReporter());
        // a file where the assets directory should go: creating assets/uploads fails
        file_put_contents("{$dir}/assets", '');

        try {
            $provider->createAssetsWriteStream()->write([
                'filename' => 'photo.png',
                'filepath' => '/unused',
                'stream' => [],
                'stats' => ['size' => 0],
                'metadata' => [],
            ]);
            self::fail('Expected the write to fail');
        } catch (ProviderTransferError $err) {
            self::assertStringContainsString('photo.png', $err->getMessage());
            self::assertInstanceOf(\Throwable::class, $err->details['details']['details']['error'] ?? null);
        }
    }

    public function testWritesTheUnpackedArchiveLayout(): void
    {
        $dir = $this->tempDir() . '/export';
        $provider = Destination::createLocalDirectoryDestinationProvider(['directory' => ['path' => $dir], 'file' => []]);
        $provider->setMetadata('source', ['createdAt' => '2026-01-01T00:00:00.000Z', 'strapi' => ['version' => '5.56.0']]);
        $provider->bootstrap(Diagnostic::createDiagnosticReporter());

        $entities = $provider->createEntitiesWriteStream();
        $entities->write(['type' => 'api::test.test', 'id' => 1, 'data' => []]);
        $entities->end();
        $assets = $provider->createAssetsWriteStream();
        $assets->write(['filename' => 'photo.jpg', 'stream' => ['jpeg', '-bytes'], 'stats' => ['size' => 10], 'metadata' => ['hash' => 'photo']]);
        $assets->end();
        $provider->close();

        self::assertSame("{\"type\":\"api::test.test\",\"id\":1,\"data\":[]}\n", file_get_contents("{$dir}/entities/entities_00001.jsonl"));
        self::assertSame('jpeg-bytes', file_get_contents("{$dir}/assets/uploads/photo.jpg"));
        self::assertSame('{"hash":"photo"}', file_get_contents("{$dir}/assets/metadata/photo.jpg.json"));
        self::assertJsonStringEqualsJsonString('{"createdAt":"2026-01-01T00:00:00.000Z","strapi":{"version":"5.56.0"}}', (string) file_get_contents("{$dir}/metadata.json"));
    }
}
