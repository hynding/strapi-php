<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\File\Providers\Destination;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\File\Providers\Destination\Destination;
use Strapi\DataTransfer\Tests\TempDir;
use Strapi\DataTransfer\Utils\Diagnostic;

/** Port of src/file/providers/destination/__tests__/cleanup.test.ts */
final class CleanupTest extends TestCase
{
    use TempDir;

    public function testCloseIsANoOpAfterRollbackFinalizesAndRemovesTheArchive(): void
    {
        $outputPath = $this->tempDir() . '/export';
        $provider = Destination::createLocalFileDestinationProvider([
            'encryption' => ['enabled' => false],
            'compression' => ['enabled' => false],
            'file' => ['path' => $outputPath],
        ]);
        $provider->setMetadata('source', ['createdAt' => gmdate('c'), 'strapi' => ['version' => '5.0.0']]);

        $provider->bootstrap(Diagnostic::createDiagnosticReporter());
        $provider->rollback();
        $provider->close();

        self::assertFileDoesNotExist("{$outputPath}.tar");
    }
}
