<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Directory\Providers;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Directory\Providers\Source\Source;
use Strapi\DataTransfer\Tests\TempDir;
use Strapi\DataTransfer\Utils\Diagnostic;

/** Port of src/directory/providers/source/__tests__/index.test.ts (backpressure has no synchronous equivalent). */
final class SourceTest extends TestCase
{
    use TempDir;

    private const array ASSET_METADATA = ['id' => 1, 'name' => 'photo.jpg', 'hash' => 'photo', 'ext' => '.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'url' => '/uploads/photo.jpg'];

    private function exportDir(?string $sidecar = null, bool $upload = false): string
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/metadata.json", (string) json_encode(['strapi' => ['version' => '5.0.0'], 'createdAt' => gmdate('c')]));
        if ($upload) {
            mkdir("{$dir}/assets/uploads", 0o777, true);
            file_put_contents("{$dir}/assets/uploads/photo.jpg", 'jpeg-bytes');
        }
        if ($sidecar !== null) {
            mkdir("{$dir}/assets/metadata", 0o777, true);
            file_put_contents("{$dir}/assets/metadata/photo.jpg.json", $sidecar);
        }

        return $dir;
    }

    private static function provider(string $dir): Source
    {
        $provider = Source::createLocalDirectorySourceProvider(['directory' => ['path' => $dir]]);
        $provider->bootstrap(Diagnostic::createDiagnosticReporter());

        return $provider;
    }

    public function testBootstrapFailsWhenMetadataJsonIsMissing(): void
    {
        $this->expectException(\Throwable::class);
        self::provider($this->tempDir());
    }

    public function testGetMetadataAndGetSchemasAfterBootstrap(): void
    {
        $dir = $this->exportDir();
        mkdir("{$dir}/schemas");
        file_put_contents("{$dir}/schemas/schemas_00000.jsonl", (string) json_encode(['uid' => 'api::test.test', 'kind' => 'collectionType', 'attributes' => ['title' => ['type' => 'string']]]) . "\n");
        $provider = self::provider($dir);

        self::assertSame('5.0.0', $provider->getMetadata()['strapi']['version'] ?? null);
        self::assertSame('api::test.test', $provider->getSchemas()['api::test.test']['uid'] ?? null);
    }

    public function testCreateAssetsReadStreamOnAnExportWithoutAssets(): void
    {
        self::assertSame([], iterator_to_array(self::provider($this->exportDir())->createAssetsReadStream(), false));
    }

    public function testAssetPreflightAcceptsUploadsWithValidSidecarMetadata(): void
    {
        $dir = $this->exportDir((string) json_encode(self::ASSET_METADATA), true);
        $provider = self::provider($dir);
        $provider->validateStage('assets');

        // the preflight keeps the metadata it read
        unlink("{$dir}/assets/metadata/photo.jpg.json");
        $assets = [];
        foreach ($provider->createAssetsReadStream() as $asset) {
            $assets[] = $asset;
            self::assertSame('jpeg-bytes', implode('', iterator_to_array($asset['stream'], false)));
        }
        self::assertCount(1, $assets);
        self::assertSame(1, $assets[0]['metadata']['id']);
        self::assertSame('photo', $assets[0]['metadata']['hash']);
    }

    public function testAssetPreflightRejectsAnUploadWithNoSidecar(): void
    {
        $provider = self::provider($this->exportDir(upload: true));

        $this->expectExceptionMessage('Asset metadata preflight failed for "photo.jpg"');
        $provider->validateStage('assets');
    }

    public function testAssetPreflightRejectsIncompleteObjectSidecarMetadata(): void
    {
        $provider = self::provider($this->exportDir('{}', true));

        $this->expectExceptionMessage('Asset sidecar metadata has invalid required fields');
        $provider->validateStage('assets');
    }

    public function testAssetPreflightRejectsNonObjectSidecarJson(): void
    {
        $provider = self::provider($this->exportDir('null', true));

        $this->expectExceptionMessage('Asset sidecar metadata must be a JSON object');
        $provider->validateStage('assets');
    }

    public function testAssetPreflightRejectsMalformedSidecarJson(): void
    {
        $provider = self::provider($this->exportDir('{not valid json', true));

        $this->expectExceptionMessage('Asset metadata preflight failed for "photo.jpg"');
        $provider->validateStage('assets');
    }

    public function testStreamsEntitiesFromJsonlShardsInOrder(): void
    {
        $dir = $this->exportDir();
        mkdir("{$dir}/entities");
        file_put_contents("{$dir}/entities/entities_00001.jsonl", (string) json_encode(['type' => 'api::test.test', 'id' => 1, 'data' => []]) . "\n");
        file_put_contents("{$dir}/entities/entities_00002.jsonl", (string) json_encode(['type' => 'api::test.test', 'id' => 2, 'data' => []]) . "\n");

        $chunks = iterator_to_array(self::provider($dir)->createEntitiesReadStream(), false);

        self::assertSame([1, 2], array_column($chunks, 'id'));
    }
}
