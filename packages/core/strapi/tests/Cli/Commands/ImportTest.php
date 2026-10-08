<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli\Commands;

use Strapi\Cli\Cli\Commands\Import\Action;
use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;

/**
 * Port of packages/core/strapi/src/cli/commands/import/__tests__/import.test.ts. The import action
 * destroys its instance when the transfer ends, so each run gets a freshly loaded one.
 */
final class ImportTest extends DataTransferCommandTestCase
{
    /** @param array<string, mixed> $opts */
    private function import(array $opts): int
    {
        $action = new Action([
            ...$this->commonDeps(),
            'createStrapiInstance' => static fn (): Strapi => Core::createStrapi(['appDir' => self::$appDir])->load(),
            'createLocalFileSourceProvider' => $this->sourceFactory('fileSource'),
            'createLocalDirectorySourceProvider' => $this->sourceFactory('dirSource'),
            'createLocalStrapiDestinationProvider' => $this->destinationFactory('strapiDestination'),
        ]);

        return $action($opts, self::input(), $this->output);
    }

    public function testCreatesProvidersWithCorrectOptions(): void
    {
        $file = $this->cwd . '/test.tar.gz.enc';
        touch($file);

        self::assertSame(0, $this->import(['file' => $file, 'decrypt' => true, 'decompress' => true, 'key' => 'k', 'exclude' => [], 'only' => []]));

        self::assertSame(LocalDestination::DEFAULT_CONFLICT_STRATEGY, $this->created['strapiDestination'][0]['strategy']);
        self::assertSubset([
            'file' => ['path' => $file],
            'encryption' => ['enabled' => true, 'key' => 'k'],
            'compression' => ['enabled' => true],
        ], $this->created['fileSource'][0]);
        self::assertSame(Engine::DEFAULT_SCHEMA_STRATEGY, $this->engineOptions[0]['schemaStrategy']);
        self::assertSame(Engine::DEFAULT_VERSION_STRATEGY, $this->engineOptions[0]['versionStrategy']);
        self::assertStringContainsString('Import process has been completed successfully!', $this->output->fetch());
    }

    public function testUsesDirectorySourceWhenBackupPathIsADirectory(): void
    {
        self::assertSame(0, $this->import(['file' => $this->cwd, 'decrypt' => false, 'decompress' => false, 'exclude' => [], 'only' => []]));

        self::assertSame([['directory' => ['path' => $this->cwd]]], $this->created['dirSource']);
        self::assertArrayNotHasKey('fileSource', $this->created);
    }

    public function testExcludesUploadTypesFromRestore(): void
    {
        $file = $this->cwd . '/test.tar';
        touch($file);

        self::assertSame(0, $this->import(['file' => $file, 'exclude' => ['files'], 'excludeContentTypes' => DataTransfer::UPLOAD_CONTENT_TYPE_UIDS, 'only' => []]));

        $restore = $this->created['strapiDestination'][0]['restore'];
        self::assertFalse($restore['assets']);
        foreach (DataTransfer::UPLOAD_CONTENT_TYPE_UIDS as $uid) {
            self::assertContains($uid, $restore['entities']['exclude']);
        }
    }
}
