<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services\Upload;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Strapi\Tests\AppTestCase;
use Strapi\Upload\Utils\Utils;

/**
 * Ports the end-to-end parts of server/src/services/__tests__/upload/uploadImage.test.ts,
 * replace.test.ts and updateFileInfo.test.ts on a booted app with the local provider.
 */
#[RequiresPhpExtension('gd')]
final class UploadImageTest extends AppTestCase
{
    /** @return \ArrayObject<string, mixed> */
    private static function inputFile(int $width, int $height, string $name = 'photo.png'): \ArrayObject
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        $path = (string) tempnam(sys_get_temp_dir(), 'strapi-upload-input-');
        imagepng($image, $path);

        return new \ArrayObject(['filepath' => $path, 'originalFilename' => $name, 'mimetype' => 'image/png', 'size' => filesize($path)]);
    }

    public function testUploadsAnImageWithItsThumbnailAndResponsiveFormats(): void
    {
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'upload', 'key' => 'settings', 'value' => ['sizeOptimization' => false, 'responsiveDimensions' => true, 'autoOrientation' => false]]);

        [$file] = Utils::getService('upload', self::strapi())->upload(['data' => [], 'files' => [self::inputFile(800, 400)]]);

        self::assertSame(800, $file['width']);
        self::assertSame(400, $file['height']);
        self::assertSame('.png', $file['ext']);
        self::assertSame('local', $file['provider']);
        self::assertSame("/uploads/{$file['hash']}.png", $file['url']);
        self::assertSame(['thumbnail', 'medium', 'small'], array_keys($file['formats']));

        $thumbnail = $file['formats']['thumbnail'];
        self::assertSame('thumbnail_photo.png', $thumbnail['name']);
        self::assertSame("thumbnail_{$file['hash']}", $thumbnail['hash']);
        self::assertSame([245, 123], [$thumbnail['width'], $thumbnail['height']]);
        self::assertSame("/uploads/thumbnail_{$file['hash']}.png", $thumbnail['url']);
        self::assertArrayNotHasKey('filepath', $thumbnail);
        self::assertArrayNotHasKey('getStream', $thumbnail);
        self::assertSame(['name', 'hash', 'ext', 'mime', 'path', 'width', 'height', 'size', 'sizeInBytes', 'url'], array_keys($thumbnail));
        self::assertSame([750, 375], [$file['formats']['medium']['width'], $file['formats']['medium']['height']]);
        self::assertSame([500, 250], [$file['formats']['small']['width'], $file['formats']['small']['height']]);

        $public = self::strapi()->dirs()->public;
        self::assertFileExists($public . $file['url']);
        self::assertFileExists($public . $thumbnail['url']);

        Utils::getService('upload', self::strapi())->remove($file);
        self::assertFileDoesNotExist($public . $file['url']);
        self::assertFileDoesNotExist($public . $thumbnail['url']);
    }

    public function testReplaceKeepsHashAndUrlAndDropsFormatsTheNewImageHasNot(): void
    {
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'upload', 'key' => 'settings', 'value' => ['sizeOptimization' => false, 'responsiveDimensions' => true, 'autoOrientation' => false]]);
        $upload = Utils::getService('upload', self::strapi());
        [$file] = $upload->upload(['data' => [], 'files' => [self::inputFile(800, 400)]]);

        $replaced = $upload->replace($file['id'], ['data' => ['fileInfo' => []], 'file' => self::inputFile(300, 200, 'smaller.png')]);

        self::assertSame($file['id'], $replaced['id']);
        self::assertSame($file['hash'], $replaced['hash']);
        self::assertSame($file['url'], $replaced['url']);
        self::assertSame('smaller.png', $replaced['name']);
        self::assertSame([300, 200], [$replaced['width'], $replaced['height']]);
        self::assertSame(['thumbnail'], array_keys($replaced['formats']));
        $public = self::strapi()->dirs()->public;
        self::assertFileDoesNotExist($public . $file['formats']['medium']['url']);
        self::assertSame([300, 200], array_slice((array) getimagesize($public . $replaced['url']), 0, 2));

        $upload->remove($replaced);
    }

    public function testUpdateFileInfoKeepsWhatIsNotSent(): void
    {
        $upload = Utils::getService('upload', self::strapi());
        [$file] = $upload->upload(['data' => ['fileInfo' => ['alternativeText' => 'alt', 'caption' => 'cap']], 'files' => [self::inputFile(20, 20)]]);

        $updated = $upload->updateFileInfo($file['id'], ['name' => 'renamed.png', 'caption' => null]);

        self::assertSame('renamed.png', $updated['name']);
        self::assertSame('alt', $updated['alternativeText']);
        self::assertSame('cap', $updated['caption']);

        $upload->remove($updated);
    }
}
