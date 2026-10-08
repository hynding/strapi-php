<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadLocal\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadLocal\UploadLocal;
use Strapi\Types\Core\Strapi;
use Strapi\Types\Core\StrapiDirectories;
use Strapi\Utils\Errors\PayloadTooLargeError;

/** Port of src/__tests__/upload-local.vitest.test.ts. */
final class UploadLocalTest extends TestCase
{
    private static string $publicDir;

    private static string $uploadsDir;

    public static function setUpBeforeClass(): void
    {
        self::$publicDir = sys_get_temp_dir() . '/strapi-upload-local-' . bin2hex(random_bytes(4));
        self::$uploadsDir = self::$publicDir . '/uploads';
        mkdir(self::$uploadsDir, 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$uploadsDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir(self::$uploadsDir);
        rmdir(self::$publicDir);
    }

    private function strapi(string $publicDir): Strapi
    {
        $strapi = $this->createMock(Strapi::class);
        $strapi->method('dirs')->willReturn(StrapiDirectories::fromRoot(dirname($publicDir), $publicDir));

        return $strapi;
    }

    /** @param array{sizeLimit?: int|float|null} $options */
    private function provider(array $options = []): UploadLocal
    {
        return UploadLocal::init($options, $this->strapi(self::$publicDir));
    }

    public function testShouldHaveRelativeUrlToFileObject(): void
    {
        $file = new \ArrayObject(['name' => 'test', 'size' => 100, 'url' => '/', 'path' => '/tmp/', 'hash' => 'test', 'ext' => '.json', 'mime' => 'application/json', 'buffer' => '']);

        $this->provider()->upload($file);

        self::assertSame('/uploads/test.json', $file['url']);
    }

    public function testReplaceShouldOverwriteFileAtSameHashAndSetUrl(): void
    {
        $oldFile = ['name' => 'replace-me', 'size' => 100, 'url' => '/uploads/replace_hash.json', 'hash' => 'replace_hash', 'ext' => '.json', 'mime' => 'application/json'];
        file_put_contents(self::$uploadsDir . '/replace_hash.json', 'old');
        $newFile = new \ArrayObject([...$oldFile, 'buffer' => 'new']);

        $this->provider()->replace($newFile, $oldFile);

        self::assertSame('/uploads/replace_hash.json', $newFile['url']);
        self::assertSame('new', file_get_contents(self::$uploadsDir . '/replace_hash.json'));
    }

    public function testReplaceShouldUnlinkTheOldFileWhenTheHashChanged(): void
    {
        file_put_contents(self::$uploadsDir . '/old_hash.json', 'old');
        $newFile = new \ArrayObject(['hash' => 'new_hash', 'ext' => '.json', 'buffer' => 'new']);

        $this->provider()->replace($newFile, ['hash' => 'old_hash', 'ext' => '.json']);

        self::assertFileDoesNotExist(self::$uploadsDir . '/old_hash.json');
        self::assertSame('new', file_get_contents(self::$uploadsDir . '/new_hash.json'));
    }

    public function testReplaceStreamShouldWriteTheStream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'streamed');
        rewind($stream);
        $newFile = new \ArrayObject(['hash' => 'stream_hash', 'ext' => '.txt', 'stream' => $stream]);

        $this->provider()->replaceStream($newFile, ['hash' => 'stream_hash', 'ext' => '.txt']);

        self::assertSame('/uploads/stream_hash.txt', $newFile['url']);
        self::assertSame('streamed', file_get_contents(self::$uploadsDir . '/stream_hash.txt'));
    }

    public function testUploadWithoutBufferOrStreamFails(): void
    {
        $this->expectExceptionMessage('Missing file buffer');
        $this->provider()->upload(new \ArrayObject(['hash' => 'x', 'ext' => '.txt']));
    }

    public function testDeleteReportsAMissingFile(): void
    {
        self::assertSame("File doesn't exist", $this->provider()->delete(['hash' => 'missing', 'ext' => '.txt']));

        file_put_contents(self::$uploadsDir . '/present.txt', 'x');
        self::assertNull($this->provider()->delete(['hash' => 'present', 'ext' => '.txt']));
        self::assertFileDoesNotExist(self::$uploadsDir . '/present.txt');
    }

    public function testCheckFileSize(): void
    {
        $this->provider()->checkFileSize(['name' => 'ok.txt', 'size' => 1], ['sizeLimit' => 1000]);

        $this->expectException(PayloadTooLargeError::class);
        $this->expectExceptionMessage('big.txt exceeds size limit of 1 KB.');
        $this->provider()->checkFileSize(['name' => 'big.txt', 'size' => 2], ['sizeLimit' => 1000]);
    }

    public function testInitFailsWithoutTheUploadsFolder(): void
    {
        $strapi = $this->strapi('/nonexistent-app/public');

        $this->expectExceptionMessage("The upload folder (/nonexistent-app/public/uploads) doesn't exist or is not accessible. Please make sure it exists.");
        UploadLocal::init([], $strapi);
    }
}
