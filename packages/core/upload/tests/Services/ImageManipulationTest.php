<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Strapi\Tests\AppTestCase;
use Strapi\Upload\Services\ImageManipulation;
use Strapi\Upload\Utils\AnimatedGif;
use Strapi\Upload\Utils\JpegOptimizer;
use Strapi\Upload\Utils\Utils;

/**
 * Port of server/src/services/__tests__/image-manipulation.test.ts, on GD.
 *
 * Deviation: GD cannot decode an animated WebP, so upstream's WebP "preserves animation frames"
 * cases become "is left unprocessed"; animated GIFs keep their frames ({@see AnimatedGif}).
 */
#[RequiresPhpExtension('gd')]
final class ImageManipulationTest extends AppTestCase
{
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$tmpDir = sys_get_temp_dir() . '/strapi-upload-tests-' . bin2hex(random_bytes(4));
        mkdir(self::$tmpDir);
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'upload', 'key' => 'settings', 'value' => ['sizeOptimization' => true, 'responsiveDimensions' => true, 'autoOrientation' => false]]);
    }

    public static function tearDownAfterClass(): void
    {
        \Strapi\Upload\Services\Upload::removeDirectory(self::$tmpDir);
        parent::tearDownAfterClass();
    }

    private static function service(): ImageManipulation
    {
        return Utils::getService('image-manipulation', self::strapi());
    }

    /** @return \ArrayObject<string, mixed> */
    private static function makeFile(string $fixture, string $ext, string $mime, int $width, int $height, bool $streamOnly = false): \ArrayObject
    {
        $path = __DIR__ . '/../Utils/fixtures/' . $fixture;
        $file = new \ArrayObject([
            'name' => "test{$ext}",
            'hash' => 'test_' . bin2hex(random_bytes(4)),
            'ext' => $ext,
            'mime' => $mime,
            'path' => null,
            'getStream' => static fn () => fopen($path, 'rb'),
            'width' => $width,
            'height' => $height,
            'size' => 1,
            'tmpWorkingDirectory' => self::$tmpDir,
            'folderPath' => '/',
        ]);
        if (!$streamOnly) {
            $file['filepath'] = $path;
        }

        return $file;
    }

    public function testThumbnailReportsSingleFrameDimensions(): void
    {
        $thumb = self::service()->generateThumbnail(self::makeFile('animated-test.gif', '.gif', 'image/gif', 200, 200));

        self::assertNotNull($thumb);
        self::assertLessThanOrEqual(245, $thumb['width']);
        self::assertLessThanOrEqual(156, $thumb['height']);
        self::assertSame('thumbnail_test.gif', $thumb['name']);
        self::assertStringStartsWith('thumbnail_test_', $thumb['hash']);
        self::assertFileExists($thumb['filepath']);
    }

    public function testThumbnailFromAStreamOnlyFile(): void
    {
        $thumb = self::service()->generateThumbnail(self::makeFile('strapi.png', '.png', 'image/png', 256, 256, true));

        self::assertNotNull($thumb);
        self::assertSame(156, $thumb['width']);
        self::assertSame(156, $thumb['height']);
        self::assertSame(getimagesize($thumb['filepath'])[0] ?? null, 156);
    }

    public function testThumbnailPreservesGifAnimationFrames(): void
    {
        foreach ([false, true] as $streamOnly) {
            $thumb = self::service()->generateThumbnail(self::makeFile('animated-test.gif', '.gif', 'image/gif', 200, 200, $streamOnly));

            self::assertNotNull($thumb);
            $data = (string) file_get_contents($thumb['filepath']);
            self::assertTrue(AnimatedGif::isAnimated($data));
            self::assertSame(3, substr_count($data, "\x21\xF9\x04"));
            self::assertSame([156, 156], array_slice((array) getimagesizefromstring($data), 0, 2));
        }
    }

    public function testAnimatedWebpIsLeftUnprocessed(): void
    {
        $file = self::makeFile('animated-test.webp', '.webp', 'image/webp', 200, 200);

        self::assertTrue(self::service()->isImage($file));
        self::assertFalse(self::service()->isFaultyImage($file));
        self::assertFalse(self::service()->isResizableImage($file));
        self::assertSame($file, self::service()->optimize($file));
        self::assertSame(['width' => 200, 'height' => 200], self::service()->getDimensions($file));
    }

    public function testNoThumbnailForSmallImages(): void
    {
        self::assertNull(self::service()->generateThumbnail(self::makeFile('rec.jpg', '.jpg', 'image/jpeg', 20, 20)));
    }

    public function testGifIsNotOptimized(): void
    {
        $file = self::makeFile('animated-test.gif', '.gif', 'image/gif', 200, 200);

        self::assertSame($file, self::service()->optimize($file));
    }

    public function testOptimizeKeepsDimensionsAndFillsSizes(): void
    {
        $result = self::service()->optimize(self::makeFile('rec.jpg', '.jpg', 'image/jpeg', 20, 20));

        self::assertSame(20, $result['width']);
        self::assertSame(20, $result['height']);
        // sharp's output for this fixture: 0.27 KB (optimal Huffman tables, no JFIF header)
        self::assertEqualsWithDelta(0.27, $result['size'], 0.01);
        self::assertSame(filesize($result['filepath']), $result['sizeInBytes']);
    }

    public function testGetDimensions(): void
    {
        self::assertSame(['width' => 200, 'height' => 200], self::service()->getDimensions(self::makeFile('animated-test.gif', '.gif', 'image/gif', 0, 0)));
        self::assertSame(['width' => 256, 'height' => 256], self::service()->getDimensions(self::makeFile('strapi.svg', '.svg', 'image/svg+xml', 0, 0)));
        self::assertSame(['width' => 256, 'height' => 256], self::service()->getDimensions(self::makeFile('strapi.tiff', '.tiff', 'image/tiff', 0, 0)));
    }

    public function testResponsiveFormatsFollowTheBreakpoints(): void
    {
        $image = imagecreatetruecolor(1200, 600);
        $path = self::$tmpDir . '/big.png';
        imagepng($image, $path);
        $file = new \ArrayObject(['name' => 'big.png', 'hash' => 'big_x', 'ext' => '.png', 'mime' => 'image/png', 'filepath' => $path, 'tmpWorkingDirectory' => self::$tmpDir, 'getStream' => static fn () => fopen($path, 'rb')]);

        $formats = self::service()->generateResponsiveFormats($file);

        self::assertSame(['large', 'medium', 'small'], array_column($formats, 'key'));
        self::assertSame([1000, 500], [$formats[0]['file']['width'], $formats[0]['file']['height']]);
        self::assertSame([750, 375], [$formats[1]['file']['width'], $formats[1]['file']['height']]);
        self::assertSame([500, 250], [$formats[2]['file']['width'], $formats[2]['file']['height']]);
        self::assertSame('large_big.png', $formats[0]['file']['name']);
        self::assertSame('large_big_x', $formats[0]['file']['hash']);
    }

    public function testImageDetection(): void
    {
        $service = self::service();
        foreach (['strapi.png', 'strapi.gif', 'strapi.webp', 'strapi.tiff', 'strapi.svg', 'rec.jpg'] as $image) {
            self::assertTrue($service->isImage(self::makeFile($image, '', '', 0, 0)), $image);
        }
        self::assertFalse($service->isImage(self::makeFile('rec.pdf', '', '', 0, 0)));
        self::assertTrue($service->isOptimizableImage(self::makeFile('rec.jpg', '', '', 0, 0)));
        self::assertFalse($service->isOptimizableImage(self::makeFile('strapi.svg', '', '', 0, 0)));
        self::assertTrue($service->isResizableImage(self::makeFile('strapi.png', '', '', 0, 0)));
        self::assertFalse($service->isResizableImage(self::makeFile('strapi.svg', '', '', 0, 0)));
        self::assertFalse($service->isFaultyImage(self::makeFile('rec.jpg', '', '', 0, 0)));

        $corrupt = self::$tmpDir . '/corrupt.png';
        file_put_contents($corrupt, substr((string) file_get_contents(__DIR__ . '/../Utils/fixtures/strapi.png'), 0, 60));
        self::assertTrue($service->isFaultyImage(new \ArrayObject(['filepath' => $corrupt])));
    }

    public function testGenerateFileName(): void
    {
        self::assertMatchesRegularExpression('/^File_and_Naeme_[0-9a-f]{10}$/', self::service()->generateFileName('File%&Näme'));
    }

    public function testJpegOptimizerIsLossless(): void
    {
        $image = imagecreatefromjpeg(__DIR__ . '/../Utils/fixtures/rec.jpg');
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();

        $optimized = JpegOptimizer::optimize($jpeg);
        self::assertLessThan(strlen($jpeg), strlen($optimized));

        $a = imagecreatefromstring($jpeg);
        $b = imagecreatefromstring($optimized);
        self::assertInstanceOf(\GdImage::class, $a);
        self::assertInstanceOf(\GdImage::class, $b);
        for ($x = 0; $x < 20; $x++) {
            for ($y = 0; $y < 20; $y++) {
                self::assertSame(imagecolorat($a, $x, $y), imagecolorat($b, $x, $y));
            }
        }
        self::assertSame('not a jpeg', JpegOptimizer::optimize('not a jpeg'));
    }
}
