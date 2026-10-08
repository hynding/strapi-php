<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Utils\GdImage;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\File as FileUtils;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/services/image-manipulation.ts. `sharp` is {@see GdImage} (GD): formats GD
 * cannot decode (TIFF, SVG) keep their metadata (dimensions) but are neither resized nor
 * optimized; animated GIFs are resized frame by frame, an animated WebP (which GD cannot decode)
 * is neither resized nor optimized. Output dimensions, names
 * (`thumbnail_<name>`, `<breakpoint>_<name>`), hashes and the size fields follow upstream's rules.
 *
 * Files are `\ArrayObject`s (or arrays): `filepath`, `getStream` (a closure returning a stream
 * resource), `tmpWorkingDirectory`, `name`, `hash`, `ext`, `mime`, `path`.
 *
 * @phpstan-type Dimensions array{width: int|null, height: int|null}
 */
final class ImageManipulation
{
    private const array FORMATS_TO_RESIZE = ['jpeg', 'png', 'webp', 'tiff', 'gif'];
    private const array FORMATS_TO_PROCESS = ['jpeg', 'png', 'webp', 'tiff', 'svg', 'gif', 'avif'];
    private const array FORMATS_TO_OPTIMIZE = ['jpeg', 'png', 'webp', 'tiff', 'avif'];

    public const array THUMBNAIL_RESIZE_OPTIONS = [
        'width' => 245,
        'height' => 156,
        'fit' => 'inside',
    ];

    public const array DEFAULT_BREAKPOINTS = [
        'large' => 1000,
        'medium' => 750,
        'small' => 500,
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private static function isOptimizableFormat(?string $format): bool
    {
        return $format !== null && in_array($format, self::FORMATS_TO_OPTIMIZE, true);
    }

    /**
     * The sharp input for a file: its path, or (no `filepath`) the bytes of `getStream()`.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return array{path?: string, buffer?: string}
     */
    private static function input(\ArrayAccess|array $file): array
    {
        $filepath = $file['filepath'] ?? null;
        if (is_string($filepath) && $filepath !== '') {
            return ['path' => $filepath];
        }

        $getStream = $file['getStream'] ?? null;
        if (!is_callable($getStream)) {
            throw new \RuntimeException('Input file is missing');
        }

        return ['buffer' => FileUtils::streamToBuffer($getStream())];
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return array{format: string, width: int|null, height: int|null, size?: int, orientation?: int}
     */
    private static function getMetadata(\ArrayAccess|array $file): array
    {
        return GdImage::metadata(self::input($file));
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return Dimensions
     */
    public function getDimensions(\ArrayAccess|array $file): array
    {
        $metadata = self::getMetadata($file);

        return ['width' => $metadata['width'] ?? null, 'height' => $metadata['height'] ?? null];
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param array{width: int, height: int, fit?: string} $options
     * @param array{name: string, hash: string} $naming
     * @return \ArrayObject<string, mixed>
     */
    private static function resizeFileTo(\ArrayAccess|array $file, array $options, array $naming): \ArrayObject
    {
        $tmpWorkingDirectory = $file['tmpWorkingDirectory'] ?? null;
        $filePath = is_string($tmpWorkingDirectory) && $tmpWorkingDirectory !== '' ? $tmpWorkingDirectory . '/' . $naming['hash'] : $naming['hash'];

        $newInfo = GdImage::resize(self::input($file), $filePath, $options['width'], $options['height']);

        $path = $file['path'] ?? null;
        $newFile = new \ArrayObject([
            'name' => $naming['name'],
            'hash' => $naming['hash'],
            'ext' => $file['ext'] ?? null,
            'mime' => $file['mime'] ?? null,
            'filepath' => $filePath,
            'path' => $path ?: null,
            'getStream' => static fn () => self::openStream($filePath),
        ]);

        $size = $newInfo['size'];
        $newFile['width'] = $newInfo['width'];
        $newFile['height'] = $newInfo['height'];
        $newFile['size'] = $size ? FileUtils::bytesToKbytes($size) : 0;
        $newFile['sizeInBytes'] = $size;

        return $newFile;
    }

    /** @return resource */
    private static function openStream(string $path)
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("ENOENT: no such file or directory, open '{$path}'");
        }

        return $stream;
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return \ArrayObject<string, mixed>|null
     */
    public function generateThumbnail(\ArrayAccess|array $file): ?\ArrayObject
    {
        $width = $file['width'] ?? null;
        $height = $file['height'] ?? null;
        if (
            $width
            && $height
            && ($width > self::THUMBNAIL_RESIZE_OPTIONS['width'] || $height > self::THUMBNAIL_RESIZE_OPTIONS['height'])
        ) {
            return self::resizeFileTo($file, self::THUMBNAIL_RESIZE_OPTIONS, [
                'name' => 'thumbnail_' . self::str($file['name'] ?? null),
                'hash' => 'thumbnail_' . self::str($file['hash'] ?? null),
            ]);
        }

        return null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Optimize image by:
     *    - auto orienting image based on EXIF data
     *    - reduce image quality
     *
     * @param \ArrayObject<string, mixed> $file
     * @return \ArrayObject<string, mixed>
     */
    public function optimize(\ArrayObject $file): \ArrayObject
    {
        $settings = Utils::getService('upload', $this->strapi)->getSettings() ?? [];
        $sizeOptimization = (bool) ($settings['sizeOptimization'] ?? false);
        $autoOrientation = (bool) ($settings['autoOrientation'] ?? false);

        $metadata = self::getMetadata($file);
        $format = $metadata['format'];
        $size = $metadata['size'] ?? null;

        // GD cannot re-encode every format sharp can (TIFF): those are left as they are
        if (($sizeOptimization || $autoOrientation) && self::isOptimizableFormat($format) && GdImage::canProcess($format)) {
            $hash = self::str($file['hash'] ?? null);
            $tmpWorkingDirectory = $file['tmpWorkingDirectory'] ?? null;
            $filePath = is_string($tmpWorkingDirectory) && $tmpWorkingDirectory !== ''
                ? "{$tmpWorkingDirectory}/optimized-{$hash}"
                : "optimized-{$hash}";

            try {
                $newInfo = GdImage::optimize(self::input($file), $filePath, $sizeOptimization ? 80 : 100, $autoOrientation);
            } catch (\Throwable) {
                // GD could not decode it (an animated WebP…): keep the original
                return $file;
            }

            $newSize = $newInfo['size'];

            $newFile = new \ArrayObject($file->getArrayCopy());
            $newFile['getStream'] = static fn () => self::openStream($filePath);
            $newFile['filepath'] = $filePath;

            if ($newSize && $size && $newSize > $size) {
                // Ignore optimization if output is bigger than original
                return $file;
            }

            $newFile['width'] = $newInfo['width'];
            $newFile['height'] = $newInfo['height'];
            $newFile['size'] = $newSize ? FileUtils::bytesToKbytes($newSize) : 0;
            $newFile['sizeInBytes'] = $newSize;

            return $newFile;
        }

        return $file;
    }

    /** @return array<string, int> */
    private function getBreakpoints(): array
    {
        $breakpoints = $this->strapi->config()->get('plugin::upload.breakpoints', self::DEFAULT_BREAKPOINTS);

        return is_array($breakpoints) ? $breakpoints : self::DEFAULT_BREAKPOINTS;
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return list<array{key: string, file: \ArrayObject<string, mixed>}>
     */
    public function generateResponsiveFormats(\ArrayAccess|array $file): array
    {
        $settings = Utils::getService('upload', $this->strapi)->getSettings() ?? [];
        $responsiveDimensions = (bool) ($settings['responsiveDimensions'] ?? false);

        if (!$responsiveDimensions) {
            return [];
        }

        $originalDimensions = $this->getDimensions($file);

        $results = [];

        foreach ($this->getBreakpoints() as $key => $breakpoint) {
            if (self::breakpointSmallerThan((int) $breakpoint, $originalDimensions)) {
                $results[] = self::generateBreakpoint((string) $key, $file, (int) $breakpoint);
            }
        }

        return $results;
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return array{key: string, file: \ArrayObject<string, mixed>}
     */
    private static function generateBreakpoint(string $key, \ArrayAccess|array $file, int $breakpoint): array
    {
        $newFile = self::resizeFileTo(
            $file,
            ['width' => $breakpoint, 'height' => $breakpoint, 'fit' => 'inside'],
            [
                'name' => "{$key}_" . self::str($file['name'] ?? null),
                'hash' => "{$key}_" . self::str($file['hash'] ?? null),
            ],
        );

        return ['key' => $key, 'file' => $newFile];
    }

    /** @param Dimensions $dimensions */
    private static function breakpointSmallerThan(int $breakpoint, array $dimensions): bool
    {
        return $breakpoint < ($dimensions['width'] ?? 0) || $breakpoint < ($dimensions['height'] ?? 0);
    }

    /**
     * Applies a simple image transformation to see if the image is faulty/corrupted.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     */
    public function isFaultyImage(\ArrayAccess|array $file): bool
    {
        $filepath = $file['filepath'] ?? null;
        if (!is_string($filepath) || $filepath === '') {
            // upstream resolves with the stats object (truthy) or rejects
            GdImage::stats(self::input($file));

            return true;
        }

        try {
            GdImage::stats(['path' => $filepath]);

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    private static function formatOf(\ArrayAccess|array $file): ?string
    {
        try {
            return self::getMetadata($file)['format'];
        } catch (\Throwable) {
            // throw when the file is not a supported image
            return null;
        }
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    public function isOptimizableImage(\ArrayAccess|array $file): bool
    {
        $format = self::formatOf($file);

        return $format !== null && in_array($format, self::FORMATS_TO_OPTIMIZE, true);
    }

    /**
     * Resizable for sharp *and* decodable by GD: a TIFF or an animated WebP is reported as an
     * image (with its dimensions) but gets no thumbnail or responsive formats.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     */
    public function isResizableImage(\ArrayAccess|array $file): bool
    {
        $format = self::formatOf($file);

        return $format !== null && in_array($format, self::FORMATS_TO_RESIZE, true) && GdImage::canDecode(self::input($file), $format);
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    public function isImage(\ArrayAccess|array $file): bool
    {
        $format = self::formatOf($file);

        return $format !== null && in_array($format, self::FORMATS_TO_PROCESS, true);
    }

    public function generateFileName(string $name): string
    {
        $randomSuffix = bin2hex(random_bytes(5));
        $baseName = Strings::slugify($name, ['separator' => '_', 'lowercase' => false]);

        return "{$baseName}_{$randomSuffix}";
    }
}
