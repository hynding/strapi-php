<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services;

use Strapi\Core\Utils\Fetch;
use Strapi\Tests\AppTestCase;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Services\File;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/__tests__/file.test.ts. Upstream mocks `strapi.fetch`; here
 * {@see Fetch::intercept()} answers it, for a URL on a public IP literal (no DNS). Its body is one
 * buffered stream, so the cases about chunk boundaries are not ported.
 */
final class FileTest extends AppTestCase
{
    private const string URL = 'https://93.184.215.14/files/photo.jpg';

    private string $tmpWorkingDirectory = '';

    protected function setUp(): void
    {
        $this->tmpWorkingDirectory = sys_get_temp_dir() . '/strapi-url-upload-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpWorkingDirectory);
    }

    protected function tearDown(): void
    {
        Fetch::intercept(null);
        array_map('unlink', glob($this->tmpWorkingDirectory . '/*') ?: []);
        @rmdir($this->tmpWorkingDirectory);
    }

    /** @param array<string, string> $headers */
    private static function mockFetch(string $body, array $headers = [], int $status = 200): void
    {
        Fetch::intercept(static fn (): array => ['status' => $status, 'statusText' => 'OK', 'headers' => $headers, 'body' => $body]);
    }

    /**
     * @param (callable(array{bytesWritten: int, totalBytes: int|null}): void)|null $onProgress
     * @return array<string, mixed>
     */
    private function fetchUrl(int|float|null $sizeLimit = null, ?callable $onProgress = null): array
    {
        return Utils::getService('file', self::strapi())->fetchUrlToInputFile(self::URL, $this->tmpWorkingDirectory, $sizeLimit, $onProgress)['file']->getArrayCopy();
    }

    /** @return list<string> */
    private function tmpFiles(): array
    {
        return array_values(array_diff(scandir($this->tmpWorkingDirectory) ?: [], ['.', '..']));
    }
    public function testGetFolderPath(): void
    {
        $fileService = Utils::getService('file', self::strapi());
        $folder = Utils::getService('folder', self::strapi())->create(['name' => 'file-test-folder']);

        self::assertSame('/', $fileService->getFolderPath(null));
        self::assertSame($folder['path'], $fileService->getFolderPath($folder['id']));
    }

    public function testDeleteTwoFiles(): void
    {
        $db = self::strapi()->db();
        $ids = [];
        foreach (['a', 'b'] as $name) {
            $ids[] = $db->query('plugin::upload.file')->create(['data' => [
                'name' => $name, 'hash' => "hash-{$name}", 'ext' => '.txt', 'mime' => 'text/plain', 'size' => 1, 'url' => "/uploads/hash-{$name}.txt", 'provider' => 'local', 'folderPath' => '/',
            ]])['id'];
        }

        $deleted = Utils::getService('file', self::strapi())->deleteByIds($ids);

        self::assertSame($ids, array_column($deleted, 'id'));
        self::assertSame(0, $db->query('plugin::upload.file')->count(['where' => ['id' => ['$in' => $ids]]]));
    }

    public function testSignFileUrls(): void
    {
        $file = ['id' => 1, 'url' => '/uploads/a.png', 'provider' => 'local', 'formats' => ['thumbnail' => ['url' => '/uploads/thumbnail_a.png']]];
        $fileService = Utils::getService('file', self::strapi());

        // Do not sign file URL when provider is not private
        self::assertSame([...$file, 'isUrlSigned' => false], $fileService->signFileUrls($file));

        $original = self::strapi()->plugin('upload')->provider;
        try {
            $instance = new class {
                public function isPrivate(): bool
                {
                    return true;
                }

                /** @param array<string, mixed> $file */
                public function getSignedUrl(array $file): array
                {
                    return ['url' => $file['url'] . '?signed'];
                }

                public function delete(): void
                {
                }
            };
            self::strapi()->plugin('upload')->provider = new UploadProvider($instance);

            // Sign file URL when provider is private
            $signed = $fileService->signFileUrls($file);
            self::assertSame('/uploads/a.png?signed', $signed['url']);
            self::assertTrue($signed['isUrlSigned']);
            self::assertSame('/uploads/thumbnail_a.png?signed', $signed['formats']['thumbnail']['url']);
            self::assertTrue($signed['formats']['thumbnail']['isUrlSigned']);
        } finally {
            self::strapi()->plugin('upload')->provider = $original;
        }
    }

    public function testFetchUrlToInputFileRejectsAnInvalidProtocol(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Invalid URL protocol. Only http and https are allowed: ftp://example.com/file.jpg');
        Utils::getService('file', self::strapi())->fetchUrlToInputFile('ftp://example.com/file.jpg', sys_get_temp_dir());
    }

    public function testFetchUrlToInputFileRejectsAnInvalidUrl(): void
    {
        $this->expectExceptionMessage('Invalid URL: not a url');
        Utils::getService('file', self::strapi())->fetchUrlToInputFile('not a url', sys_get_temp_dir());
    }

    public function testFetchUrlToInputFileBlocksPrivateAddresses(): void
    {
        foreach (['http://127.0.0.1/x', 'http://10.1.2.3/x', 'http://169.254.169.254/latest', 'http://[::1]/x', 'http://192.168.0.1/'] as $url) {
            try {
                Utils::getService('file', self::strapi())->fetchUrlToInputFile($url, sys_get_temp_dir());
                self::fail("{$url} should be blocked");
            } catch (ApplicationError $error) {
                self::assertSame("URL resolves to a blocked address: {$url}", $error->getMessage());
            }
        }
        self::assertFalse(File::isBlockedAddress('93.184.216.34'));
        self::assertTrue(File::isBlockedAddress('172.20.0.1'));
        self::assertFalse(File::isBlockedAddress('172.32.0.1'));
        self::assertTrue(File::isBlockedAddress('fd00::1'));
    }

    public function testGetFilenameFromUrl(): void
    {
        self::assertSame('photo.jpg', File::getFilenameFromUrl('https://example.com/a/photo.jpg?x=1'));
        self::assertSame('my file.png', File::getFilenameFromUrl('https://example.com/a/my%20file.png'));
        self::assertSame('cd.jpg', File::getFilenameFromUrl('https://example.com/a/x', 'attachment; filename="cd.jpg"'));
        self::assertSame('passwd', File::getFilenameFromUrl('https://example.com/x', "attachment; filename*=UTF-8''..%2F..%2Fetc%2Fpasswd"));
        self::assertMatchesRegularExpression('/^untitled_\d{4}-\d{2}-\d{2}_\d{6}$/', File::getFilenameFromUrl('https://example.com/'));
    }

    public function testStreamsTheBodyToDisk(): void
    {
        self::mockFetch('hello streamed world');

        $file = $this->fetchUrl();

        self::assertSame($this->tmpWorkingDirectory . '/photo.jpg', $file['filepath']);
        self::assertSame('hello streamed world', file_get_contents($file['filepath']));
    }

    public function testDerivesTheSizeFromTheFileWrittenOnDisk(): void
    {
        self::mockFetch(str_repeat("\1", 500) . str_repeat("\2", 300));

        $file = $this->fetchUrl();

        self::assertSame(filesize($file['filepath']), $file['size']);
        self::assertSame(800, $file['size']);
    }

    public function testRejectsWhenTheStreamedBytesExceedSizeLimitAndNoContentLengthIsSent(): void
    {
        self::mockFetch(str_repeat("\3", 500));

        try {
            $this->fetchUrl(250);
            self::fail('the import should be rejected');
        } catch (ApplicationError $error) {
            self::assertStringContainsString('File too large', $error->getMessage());
        }
        self::assertSame([], $this->tmpFiles());
    }

    public function testRejectsWhenContentLengthUnderstatesTheRealBodySize(): void
    {
        self::mockFetch(str_repeat("\4", 500), ['content-length' => '50']);

        try {
            $this->fetchUrl(250);
            self::fail('the import should be rejected');
        } catch (ApplicationError $error) {
            self::assertStringContainsString('File too large', $error->getMessage());
        }
        self::assertSame([], $this->tmpFiles());
    }

    public function testRejectsEarlyWhenContentLengthExceedsSizeLimit(): void
    {
        self::mockFetch(str_repeat("\0", 10), ['content-length' => (string) (5 * 1024 * 1024)]);
        $progress = [];

        try {
            $this->fetchUrl(1024 * 1024, static function (array $p) use (&$progress): void {
                $progress[] = $p;
            });
            self::fail('the import should be rejected');
        } catch (ApplicationError $error) {
            self::assertSame('File too large: maximum allowed size is 1 MB', $error->getMessage());
        }
        // Rejected on the header fast-path, so no progress is ever announced
        self::assertSame([], $progress);
        self::assertSame([], $this->tmpFiles());
    }

    public function testReportsSmallSizeLimitsInAReadableUnit(): void
    {
        $sizeLimit = 200 * 1000;
        self::mockFetch(str_repeat("\1", $sizeLimit + 1));

        $this->expectExceptionMessage('File too large: maximum allowed size is 200 KB');
        $this->fetchUrl($sizeLimit);
    }

    public function testReportsProgressWithTheParsedContentLengthAsTotalBytes(): void
    {
        self::mockFetch(str_repeat("\6", 300), ['content-length' => '300']);
        $progress = [];

        $this->fetchUrl(null, static function (array $p) use (&$progress): void {
            $progress[] = $p;
        });

        self::assertSame([['bytesWritten' => 0, 'totalBytes' => 300], ['bytesWritten' => 300, 'totalBytes' => 300]], $progress);
    }

    public function testReportsTotalBytesAsNullThroughoutWhenContentLengthIsAbsent(): void
    {
        self::mockFetch(str_repeat("\3", 25));
        $progress = [];

        $this->fetchUrl(null, static function (array $p) use (&$progress): void {
            $progress[] = $p;
        });

        self::assertSame([['bytesWritten' => 0, 'totalBytes' => null], ['bytesWritten' => 25, 'totalBytes' => null]], $progress);
    }

    public function testIgnoresAThrowingProgressCallback(): void
    {
        self::mockFetch('still written');
        $calls = 0;

        $file = $this->fetchUrl(null, static function () use (&$calls): void {
            ++$calls;

            throw new \RuntimeException('consumer bug');
        });

        self::assertSame('still written', file_get_contents($file['filepath']));
        self::assertSame(13, $file['size']);
        self::assertSame(2, $calls);
    }

    public function testWritesAnEmptyFileWhenTheResponseHasNoBody(): void
    {
        self::mockFetch('');
        $progress = [];

        $file = $this->fetchUrl(null, static function (array $p) use (&$progress): void {
            $progress[] = $p;
        });

        self::assertSame(0, $file['size']);
        self::assertSame('', file_get_contents($file['filepath']));
        self::assertSame([['bytesWritten' => 0, 'totalBytes' => null]], $progress);
    }

    public function testRejectsANonSuccessfulResponse(): void
    {
        self::mockFetch('', [], 404);

        $this->expectExceptionMessage('Failed to fetch URL: ' . self::URL . ' (404 OK)');
        $this->fetchUrl();
    }
}
