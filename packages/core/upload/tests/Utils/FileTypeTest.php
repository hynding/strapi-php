<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Upload\Utils\FileType;
use Strapi\Upload\Utils\MimeTypes;

/**
 * PHP-port additions: {@see FileType} (for `file-type`) and {@see MimeTypes} (for `mime-types`).
 * The expected values are what the npm packages return for the same input.
 */
final class FileTypeTest extends TestCase
{
    private static function fixtures(): string
    {
        return __DIR__ . '/fixtures';
    }

    /** @return list<array{string, string|null}> */
    public static function fixtureTypes(): array
    {
        return [
            ['rec.jpg', 'image/jpeg'],
            ['rec.pdf', 'application/pdf'],
            ['rec.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['rec.txt', null],
            ['strapi.png', 'image/png'],
            ['strapi.gif', 'image/gif'],
            ['strapi.webp', 'image/webp'],
            ['strapi.tiff', 'image/tiff'],
            ['strapi.svg', 'application/xml'],
        ];
    }

    #[DataProvider('fixtureTypes')]
    public function testDetectsLikeFileType(string $file, ?string $mime): void
    {
        $buffer = (string) file_get_contents(self::fixtures() . '/' . $file, false, null, 0, 4100);

        self::assertSame($mime, FileType::fromBuffer($buffer)['mime'] ?? null);
    }

    public function testMimeTypesLookup(): void
    {
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', MimeTypes::lookup('.docx'));
        self::assertSame('image/jpeg', MimeTypes::lookup('a/b/photo.JPG'));
        self::assertSame('text/plain', MimeTypes::lookup('txt'));
        self::assertFalse(MimeTypes::lookup('file'));
        self::assertFalse(MimeTypes::lookup('.unknownext'));
    }

    public function testMimeTypesExtension(): void
    {
        self::assertSame('jpeg', MimeTypes::extension('image/jpeg'));
        self::assertSame('svg', MimeTypes::extension('image/svg+xml'));
        self::assertSame('png', MimeTypes::extension('image/png; charset=binary'));
        self::assertFalse(MimeTypes::extension('application/x-nothing'));
    }

    public function testExtname(): void
    {
        self::assertSame('.png', MimeTypes::extname('a/file.name.png'));
        self::assertSame('', MimeTypes::extname('.bashrc'));
        self::assertSame('', MimeTypes::extname('data'));
    }
}
