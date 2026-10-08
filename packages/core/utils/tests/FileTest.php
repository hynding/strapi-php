<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\File;

/** Port of __tests__/stream-to-buffer.test.ts, plus the other file.ts helpers. */
final class FileTest extends TestCase
{
    /**
     * @param list<string> $chunks
     * @return resource
     */
    private static function resource(array $chunks)
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);
        foreach ($chunks as $chunk) {
            fwrite($handle, $chunk);
        }
        rewind($handle);

        return $handle;
    }

    public function testBuffersStringsEmittedByAnIterable(): void
    {
        self::assertSame('hello world', File::streamToBuffer(['hello', ' ', 'world']));
    }

    public function testBuffersUtf8DataWithAMultibyteCharacterSplitBetweenChunks(): void
    {
        $input = 'café 🌍';
        $stream = [substr($input, 0, 4), substr($input, 4, 4), substr($input, 8)];

        self::assertSame($input, File::streamToBuffer($stream));
    }

    public function testPreservesBinaryStringsAndByteListChunks(): void
    {
        self::assertSame("\x00\xff\x80\x01", File::streamToBuffer(["\x00\xff", [128, 1]]));
    }

    public function testConcatenatesBinaryChunksFromAResource(): void
    {
        self::assertSame("\x00\xff\x80\x01", File::streamToBuffer(self::resource(["\x00\xff", "\x80\x01"])));
    }

    public function testReadsAPsr7LikeStream(): void
    {
        $stream = new class () {
            private int $pos = 0;

            public function eof(): bool
            {
                return $this->pos >= 2;
            }

            public function read(int $length): string
            {
                return ['hello ', 'café'][$this->pos++];
            }
        };

        self::assertSame('hello café', File::streamToBuffer($stream));
    }

    public function testReturnsAnEmptyStringForAnEmptyStream(): void
    {
        self::assertSame('', File::streamToBuffer([]));
        self::assertSame('', File::streamToBuffer(self::resource([])));
    }

    public function testThrowsTheOriginalStreamError(): void
    {
        $error = new \RuntimeException('read failed');
        $stream = (static function () use ($error): \Generator {
            yield 'hello';
            throw $error;
        })();

        try {
            File::streamToBuffer($stream);
            self::fail('Expected the stream error');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
    }

    private static function unsupported(): mixed
    {
        return unserialize('i:42;');
    }

    public function testRejectsAnUnsupportedValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        File::streamToBuffer(self::unsupported());
    }

    public function testGetStreamSize(): void
    {
        self::assertSame(13, File::getStreamSize(['hello ', 'café ', [1]]));
        self::assertSame(4, File::getStreamSize(self::resource(["\x00\xff", "\x80\x01"])));
    }

    public function testByteConversions(): void
    {
        self::assertSame(2000, File::kbytesToBytes(2));
        self::assertSame(1500.0, File::kbytesToBytes(1.5));
        self::assertSame(1.23, File::bytesToKbytes(1234));
        self::assertSame(0.0, File::bytesToKbytes(0));
        self::assertSame('0 Bytes', File::bytesToHumanReadable(0));
        self::assertSame('512 Bytes', File::bytesToHumanReadable(512));
        self::assertSame('2 KB', File::bytesToHumanReadable(1500));
        self::assertSame('3 MB', File::bytesToHumanReadable(2_500_000));
        self::assertSame('5 GB', File::bytesToHumanReadable(5_000_000_001));
    }

    public function testWritableDiscardStream(): void
    {
        $stream = File::writableDiscardStream();
        self::assertSame(5, fwrite($stream, 'hello'));
        fclose($stream);
    }
}
