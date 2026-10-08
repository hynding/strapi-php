<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\File\Providers\Destination;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\File\Providers\Destination\Utils;
use Strapi\DataTransfer\Utils\Tar\Pack;

/** Port of src/file/providers/destination/__tests__/utils.test.ts */
final class UtilsTest extends TestCase
{
    public function testReturnsAFilePathWhenCallingTheFactory(): void
    {
        $filePathFactory = Utils::createFilePathFactory('entities');

        self::assertSame('entities/entities_00000.jsonl', $filePathFactory(0));
    }

    /** @return iterable<array{string, int, string}> */
    public static function cases(): iterable
    {
        yield ['schemas', 0, 'schemas/schemas_00000.jsonl'];
        yield ['entities', 5, 'entities/entities_00005.jsonl'];
        yield ['links', 11, 'links/links_00011.jsonl'];
        yield ['schemas', 543, 'schemas/schemas_00543.jsonl'];
        yield ['entities', 5213, 'entities/entities_05213.jsonl'];
        yield ['links', 33231, 'links/links_33231.jsonl'];
    }

    #[DataProvider('cases')]
    public function testReturnsFilePathsWhenCallingTheFactory(string $type, int $fileIndex, string $filePath): void
    {
        self::assertSame($filePath, Utils::createFilePathFactory($type)($fileIndex));
    }

    public function testThrowsAnErrorWhenThePayloadIsTooLarge(): void
    {
        $archive = new Pack(static function (): void {
        });
        $tarEntryStream = Utils::createTarEntryStream($archive, Utils::createFilePathFactory('entries'), 3);

        $this->expectExceptionMessage('payload too large: 4>3');
        $tarEntryStream->write('test');
    }

    public function testResolvesWhenThePayloadIsSmallerThanTheMaxSize(): void
    {
        $bytes = '';
        $archive = new Pack(static function (string $b) use (&$bytes): void {
            $bytes .= $b;
        });
        $tarEntryStream = Utils::createTarEntryStream($archive, Utils::createFilePathFactory('entries'), 30);

        $tarEntryStream->write('test');
        $tarEntryStream->end();

        // the first flushed file is index 1 (`fileIndex += 1` before naming it)
        self::assertStringContainsString('entries/entries_00001.jsonl', $bytes);
        self::assertStringContainsString('test', $bytes);
    }
}
