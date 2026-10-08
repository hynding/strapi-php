<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Services\File;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/services/__tests__/file.test.ts (no network: fetches stop at URL validation). */
final class FileTest extends AppTestCase
{
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
}
