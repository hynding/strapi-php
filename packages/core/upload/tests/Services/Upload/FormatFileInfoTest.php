<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services\Upload;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/services/__tests__/upload/formatFileInfo.test.ts (on a booted app). */
final class FormatFileInfoTest extends AppTestCase
{
    /** @return array<string, mixed> */
    private static function format(array $fileData, array $fileInfo = [], array $metas = []): array
    {
        return Utils::getService('upload', self::strapi())->formatFileInfo($fileData, $fileInfo, $metas);
    }

    public function testGeneratesHash(): void
    {
        $result = self::format(['filename' => 'File Name.png', 'type' => 'image/png', 'size' => 1000 * 1000]);

        self::assertSame('File Name.png', $result['name']);
        self::assertStringContainsString('File_Name', $result['hash']);
        self::assertSame('.png', $result['ext']);
        self::assertSame('image/png', $result['mime']);
        self::assertEquals(1000, $result['size']);
    }

    public function testReplacesReservedAndUnsafeCharactersForUrlsAndFilesInHash(): void
    {
        $result = self::format(['filename' => 'File%&Näme.png', 'type' => 'image/png', 'size' => 1000 * 1000]);

        self::assertSame('File%&Näme.png', $result['name']);
        self::assertStringContainsString('File_and_Naeme', $result['hash']);
        self::assertSame('.png', $result['ext']);
    }

    public function testPreventsInvalidCharactersInFileName(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('File name contains invalid characters');
        self::format(['filename' => "filename.png\u{0000}", 'type' => 'image/png', 'size' => 1000 * 1000]);
    }

    public function testOverridesNameWithFileInfo(): void
    {
        $result = self::format(['filename' => 'File Name.png', 'type' => 'image/png', 'size' => 1000 * 1000], ['name' => 'Custom File Name.png']);

        self::assertSame('Custom File Name.png', $result['name']);
        self::assertStringContainsString('Custom_File_Name', $result['hash']);
    }

    public function testSetsAlternativeTextAndCaption(): void
    {
        $result = self::format(['filename' => 'File Name.png', 'type' => 'image/png', 'size' => 1000 * 1000], ['alternativeText' => 'some text', 'caption' => 'caption this']);

        self::assertSame('caption this', $result['caption']);
        self::assertSame('some text', $result['alternativeText']);
    }

    public function testSetAPathFolder(): void
    {
        $result = self::format(['filename' => 'File Name.png', 'type' => 'image/png', 'size' => 1000 * 1000], [], ['path' => 'folder']);

        self::assertStringContainsString('folder', $result['path']);
        self::assertSame('/', $result['folderPath']);
    }

    public function testUsesTheMimeExtensionWhenTheNameHasNone(): void
    {
        $result = self::format(['filename' => 'data', 'type' => 'image/jpeg', 'size' => 10]);

        self::assertSame('.jpeg', $result['ext']);
        self::assertSame(10, $result['sizeInBytes']);
        self::assertEquals(0.01, $result['size']);
    }
}
