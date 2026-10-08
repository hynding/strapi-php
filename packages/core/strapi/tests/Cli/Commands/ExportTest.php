<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli\Commands;

use Strapi\Cli\Cli\Commands\Export\Action;
use Strapi\Cli\Cli\Utils\DataTransfer;

/** Port of packages/core/strapi/src/cli/commands/export/__tests__/export.test.ts */
final class ExportTest extends DataTransferCommandTestCase
{
    /** @param array<string, mixed> $opts */
    private function export(array $opts): int
    {
        $action = new Action([
            ...$this->commonDeps(),
            'createLocalStrapiSourceProvider' => $this->sourceFactory('localStrapiSource'),
            'createLocalFileDestinationProvider' => $this->destinationFactory('fileDestination', ['file' => ['path' => 'path']]),
            'createLocalDirectoryDestinationProvider' => $this->destinationFactory('dirDestination', ['file' => ['path' => '/tmp/strapi-export-dir-test']]),
            'getDefaultExportName' => function (): string {
                $this->created['getDefaultExportName'][] = [];

                return 'defaultFilename';
            },
            'pathExists' => static fn (): bool => true,
        ]);

        return $action($opts, $this->output);
    }

    /** @return array<string, mixed> */
    private function lastFileDestinationOptions(): array
    {
        $calls = $this->created['fileDestination'] ?? [];
        self::assertNotEmpty($calls);

        return $calls[array_key_last($calls)];
    }

    public function testUsesPathProvidedByUser(): void
    {
        self::assertSame(0, $this->export(['file' => 'test']));

        self::assertSame('test', $this->lastFileDestinationOptions()['file']['path']);
        self::assertArrayNotHasKey('getDefaultExportName', $this->created);
        self::assertStringContainsString('Export process has been completed successfully!', $this->output->fetch());
    }

    public function testUsesDefaultPathIfNotProvidedByUser(): void
    {
        self::assertSame(0, $this->export([]));

        self::assertCount(1, $this->created['getDefaultExportName']);
        self::assertSame('defaultFilename', $this->lastFileDestinationOptions()['file']['path']);
    }

    public function testEncryptsTheOutputFileIfSpecified(): void
    {
        self::assertSame(0, $this->export(['encrypt' => true]));

        self::assertTrue($this->lastFileDestinationOptions()['encryption']['enabled']);
    }

    public function testEncryptsTheOutputFileWithTheGivenKey(): void
    {
        self::assertSame(0, $this->export(['encrypt' => true, 'key' => 'secret-key']));

        self::assertSame(['enabled' => true, 'key' => 'secret-key'], $this->lastFileDestinationOptions()['encryption']);
    }

    public function testUsesDirectoryDestinationWhenFormatIsDir(): void
    {
        self::assertSame(0, $this->export(['format' => 'dir', 'file' => '/tmp/strapi-export-dir-test', 'encrypt' => false]));

        self::assertSame(['path' => '/tmp/strapi-export-dir-test'], $this->created['dirDestination'][0]['directory']);
        self::assertArrayNotHasKey('fileDestination', $this->created);
    }

    public function testRejectsWhenFormatIsDirWithEncryptEnabled(): void
    {
        self::assertSame(1, $this->export(['format' => 'dir', 'file' => '/tmp/x', 'encrypt' => true]));
    }

    public function testRejectsWhenFormatIsDirWithoutExplicitEncryptFalse(): void
    {
        self::assertSame(1, $this->export(['format' => 'dir', 'file' => '/tmp/x']));
    }

    public function testAllowsFormatDirWhenCompressIsTrue(): void
    {
        self::assertSame(0, $this->export(['format' => 'dir', 'file' => '/tmp/strapi-export-dir-test', 'encrypt' => false, 'compress' => true]));

        self::assertArrayHasKey('dirDestination', $this->created);
    }

    public function testUsesCompressOption(): void
    {
        self::assertSame(0, $this->export(['compress' => false]));
        self::assertSame(['enabled' => false], $this->lastFileDestinationOptions()['compression']);

        self::assertSame(0, $this->export(['compress' => true]));
        self::assertSame(['enabled' => true], $this->lastFileDestinationOptions()['compression']);
    }

    public function testFiltersUploadTypesAndSkipsAssets(): void
    {
        self::assertSame(0, $this->export(['exclude' => ['files'], 'excludeContentTypes' => DataTransfer::UPLOAD_CONTENT_TYPE_UIDS]));

        $options = $this->engineOptions[array_key_last($this->engineOptions)];
        self::assertSame(['files'], $options['exclude']);
        $entityFilter = $options['transforms']['entities'][0]['filter'];
        $linkFilter = $options['transforms']['links'][0]['filter'];
        self::assertCount(1, $options['transforms']['entities']);
        self::assertCount(1, $options['transforms']['links']);

        self::assertFalse($entityFilter(['type' => 'plugin::upload.file']));
        self::assertTrue($entityFilter(['type' => 'api::article.article']));
        self::assertFalse($linkFilter(['left' => ['type' => 'plugin::upload.file'], 'right' => ['type' => 'api::article.article']]));
    }

    public function testRejectsUnknownContentTypes(): void
    {
        self::assertSame(1, $this->export(['excludeContentTypes' => ['plugin::does-not-exist']]));
        self::assertStringContainsString('Unknown content type(s) for --exclude-content-types: plugin::does-not-exist', $this->output->fetch());
    }
}
