<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

/**
 * PHP-port addition: the part of `sharp` that services/image-manipulation.ts uses, on GD.
 *
 * - {@see self::metadata()}: `sharp(input).metadata()` — `format` named as sharp/libvips names it
 *   (`jpeg`, `png`, `webp`, `gif`, `tiff`, `svg`, and `heif` for AVIF/HEIF), `width`, `height`, and
 *   `size` for in-memory input only (sharp reports `size` for Buffer/Stream input, never for a
 *   path). Throws for what sharp cannot read (BMP, ICO, PSD, non-images…).
 * - {@see self::stats()}: `sharp(input).stats()` — throws when the pixels cannot be decoded.
 * - {@see self::resize()}: `sharp(input).resize({ width, height, fit: 'inside' }).toFile(out)`,
 *   same output format as the input, sharp's default qualities (JPEG/WebP 80, AVIF 50); JPEGs get
 *   optimal Huffman tables ({@see JpegOptimizer}) as with sharp's `optimiseCoding` default.
 * - {@see self::optimize()}: `sharp(input)[format]({ quality })[.rotate()].toFile(out)`.
 *
 * Input is a path or a binary string. Differences from sharp: GD decodes JPEG, PNG, GIF, WebP and
 * AVIF only, so TIFF and SVG have metadata but no pixels ({@see self::canProcess()} is false for
 * them and the caller skips resizing/optimizing, as upstream skips formats sharp cannot handle).
 * Animated GIFs are resized frame by frame ({@see AnimatedGif}); an animated WebP cannot be decoded
 * by GD and is left unprocessed.
 */
final class GdImage
{
    /** formats GD can decode and encode */
    private const array PROCESSABLE = ['jpeg', 'png', 'gif', 'webp', 'heif'];

    /**
     * @param array{path?: string|null, buffer?: string|null} $input
     * @return array{format: string, width: int|null, height: int|null, size?: int, orientation?: int}
     */
    public static function metadata(array $input): array
    {
        $buffer = $input['buffer'] ?? null;
        $path = $input['path'] ?? null;

        $head = $buffer !== null ? substr($buffer, 0, 4096) : self::readHead((string) $path);

        $info = $buffer !== null ? @getimagesizefromstring($buffer) : @getimagesize((string) $path);

        $format = null;
        $width = null;
        $height = null;
        if (is_array($info)) {
            $format = match ($info[2]) {
                IMAGETYPE_JPEG => 'jpeg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => 'webp',
                IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM => 'tiff',
                IMAGETYPE_AVIF => 'heif',
                default => null,
            };
            $width = $info[0] > 0 ? $info[0] : null;
            $height = $info[1] > 0 ? $info[1] : null;
        }
        // PHP 8.5's getimagesize() also knows SVG (IMAGETYPE_SVG): sniff it the same way everywhere
        if ($format === null && self::looksLikeSvg($head)) {
            $format = 'svg';
            [$width, $height] = self::svgDimensions($buffer ?? (string) @file_get_contents((string) $path));
        }

        if ($format === null) {
            throw new \RuntimeException('Input file contains unsupported image format');
        }

        $metadata = ['format' => $format, 'width' => $width, 'height' => $height];
        if ($buffer !== null) {
            $metadata['size'] = strlen($buffer);
        }
        if ($format === 'jpeg') {
            $orientation = self::exifOrientation($buffer, $path);
            if ($orientation !== null) {
                $metadata['orientation'] = $orientation;
            }
        }

        return $metadata;
    }

    /**
     * Whether GD can decode the pixels of this input: a processable format, and not an animated
     * WebP (GD's libwebp decoder reads still images only).
     *
     * @param array{path?: string|null, buffer?: string|null} $input
     */
    public static function canDecode(array $input, string $format): bool
    {
        if (!self::canProcess($format)) {
            return false;
        }
        if ($format !== 'webp') {
            return true;
        }
        $head = isset($input['buffer']) ? substr($input['buffer'], 0, 32) : self::readHead((string) ($input['path'] ?? ''));

        // RIFF....WEBPVP8X with the animation flag
        return !(substr($head, 12, 4) === 'VP8X' && (ord($head[20] ?? "\0") & 0x02) !== 0);
    }

    /** Whether GD can decode and re-encode this sharp format. */
    public static function canProcess(string $format): bool
    {
        return in_array($format, self::PROCESSABLE, true) && match ($format) {
            'webp' => function_exists('imagecreatefromwebp'),
            'heif' => function_exists('imagecreatefromavif'),
            default => true,
        };
    }

    /**
     * Decodes the pixels; throws when they cannot be.
     *
     * @param array{path?: string|null, buffer?: string|null} $input
     */
    public static function stats(array $input): void
    {
        $metadata = self::metadata($input);
        if (!self::canDecode($input, $metadata['format'])) {
            if ($metadata['format'] === 'svg' && !self::isWellFormedXml($input)) {
                throw new \RuntimeException('Input file has corrupt header');
            }

            return;
        }

        self::load($input, $metadata['format']);
    }

    /**
     * @param array{path?: string|null, buffer?: string|null} $input
     * @return array{format: string, width: int, height: int, size: int}
     */
    public static function resize(array $input, string $output, int $maxWidth, int $maxHeight): array
    {
        $metadata = self::metadata($input);
        $format = $metadata['format'];

        if ($format === 'gif') {
            // sharp `{ animated: true }`: every frame is resized
            $data = $input['buffer'] ?? (string) @file_get_contents((string) ($input['path'] ?? ''));
            $screenWidth = $metadata['width'] ?? 0;
            $screenHeight = $metadata['height'] ?? 0;
            if ($screenWidth > 0 && $screenHeight > 0 && AnimatedGif::isAnimated($data)) {
                $ratio = min($maxWidth / $screenWidth, $maxHeight / $screenHeight);
                $newWidth = max(1, (int) round($screenWidth * $ratio));
                $newHeight = max(1, (int) round($screenHeight * $ratio));
                $animated = AnimatedGif::resize($data, $newWidth, $newHeight);
                if ($animated !== null && file_put_contents($output, $animated) !== false) {
                    clearstatcache(true, $output);

                    return ['format' => $format, 'width' => $newWidth, 'height' => $newHeight, 'size' => (int) filesize($output)];
                }
            }
        }

        $image = self::load($input, $format);

        $width = imagesx($image);
        $height = imagesy($image);

        // fit: 'inside' — as large as possible while both dimensions are <= the box
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = self::canvas($image, $newWidth, $newHeight, $format);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        self::save($resized, $output, $format, null);

        clearstatcache(true, $output);

        return ['format' => $format, 'width' => $newWidth, 'height' => $newHeight, 'size' => (int) filesize($output)];
    }

    /**
     * Re-encodes with `quality` and, with `rotate`, applies the EXIF orientation.
     *
     * @param array{path?: string|null, buffer?: string|null} $input
     * @return array{format: string, width: int, height: int, size: int}
     */
    public static function optimize(array $input, string $output, int $quality, bool $rotate): array
    {
        $metadata = self::metadata($input);
        $format = $metadata['format'];
        $image = self::load($input, $format);

        if ($rotate && isset($metadata['orientation'])) {
            $image = self::orient($image, $metadata['orientation']);
        }

        self::save($image, $output, $format, $quality);
        $width = imagesx($image);
        $height = imagesy($image);

        clearstatcache(true, $output);

        return ['format' => $format, 'width' => $width, 'height' => $height, 'size' => (int) filesize($output)];
    }

    /** @param array{path?: string|null, buffer?: string|null} $input */
    private static function load(array $input, string $format): \GdImage
    {
        if (!self::canProcess($format)) {
            throw new \RuntimeException("Input file contains unsupported image format: {$format}");
        }

        $buffer = $input['buffer'] ?? null;
        if ($buffer === null) {
            $buffer = @file_get_contents((string) ($input['path'] ?? ''));
            if ($buffer === false) {
                throw new \RuntimeException('Input file is missing');
            }
        }

        $image = @imagecreatefromstring($buffer);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Input buffer contains unsupported image format');
        }

        return $image;
    }

    private static function canvas(\GdImage $source, int $width, int $height, string $format): \GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($canvas === false) {
            throw new \RuntimeException('Cannot allocate image');
        }

        if ($format === 'gif') {
            $transparent = imagecolortransparent($source);
            if ($transparent >= 0 && $transparent < imagecolorstotal($source)) {
                $color = imagecolorsforindex($source, $transparent);
                $index = imagecolorallocatealpha($canvas, $color['red'], $color['green'], $color['blue'], 127);
                if ($index !== false) {
                    imagefill($canvas, 0, 0, $index);
                    imagecolortransparent($canvas, $index);
                }
            }
        } elseif ($format !== 'jpeg') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $index = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($index !== false) {
                imagefill($canvas, 0, 0, $index);
            }
        }

        return $canvas;
    }

    private static function save(\GdImage $image, string $output, string $format, ?int $quality): void
    {
        $ok = match ($format) {
            'jpeg' => (static function () use ($image, $output, $quality): bool {
                ob_start();
                $ok = imagejpeg($image, null, $quality ?? 80);
                $jpeg = (string) ob_get_clean();

                // sharp's `optimiseCoding: true`
                return $ok && file_put_contents($output, JpegOptimizer::optimize($jpeg)) !== false;
            })(),
            // zlib level 9: PNG is lossless here (sharp's `quality` palette-quantizes)
            'png' => (static function () use ($image, $output): bool {
                imagesavealpha($image, true);

                return imagepng($image, $output, 9);
            })(),
            'gif' => imagegif($image, $output),
            'webp' => (static function () use ($image, $output, $quality): bool {
                imagesavealpha($image, true);

                return imagewebp($image, $output, $quality ?? 80);
            })(),
            'heif' => imageavif($image, $output, $quality ?? 50),
            default => false,
        };

        if (!$ok) {
            throw new \RuntimeException("Cannot write {$output}");
        }
    }

    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        $flip = in_array($orientation, [2, 4, 5, 7], true);
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270, // imagerotate is counter-clockwise
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated instanceof \GdImage) {
                $image = $rotated;
            }
        }
        if ($flip) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private static function exifOrientation(?string $buffer, ?string $path): ?int
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        $source = $buffer !== null ? 'data://image/jpeg;base64,' . base64_encode($buffer) : (string) $path;
        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : null;
    }

    private static function readHead(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Input file is missing: ' . $path);
        }
        $head = fread($handle, 4096);
        fclose($handle);

        return $head === false ? '' : $head;
    }

    private static function looksLikeSvg(string $head): bool
    {
        return preg_match('/<svg[\s>]/i', $head) === 1;
    }

    /** @param array{path?: string|null, buffer?: string|null} $input */
    private static function isWellFormedXml(array $input): bool
    {
        $content = $input['buffer'] ?? (string) @file_get_contents((string) ($input['path'] ?? ''));
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc !== false;
    }

    /** @return array{0: int|null, 1: int|null} librsvg at 72 DPI: `width`/`height`, else the viewBox */
    private static function svgDimensions(string $content): array
    {
        if (preg_match('/<svg\b[^>]*>/is', $content, $tag) !== 1) {
            return [null, null];
        }
        $attr = static function (string $name) use ($tag): ?string {
            return preg_match('/\s' . $name . '\s*=\s*["\']([^"\']*)["\']/i', $tag[0], $m) === 1 ? trim($m[1]) : null;
        };
        $length = static function (?string $value): ?float {
            if ($value === null || preg_match('/^([\d.]+)\s*(px)?$/i', $value, $m) !== 1) {
                return null;
            }

            return (float) $m[1];
        };

        $width = $length($attr('width'));
        $height = $length($attr('height'));
        $viewBox = $attr('viewBox');
        $box = $viewBox !== null ? preg_split('/[\s,]+/', $viewBox) : false;
        if (is_array($box) && count($box) === 4) {
            $boxWidth = (float) $box[2];
            $boxHeight = (float) $box[3];
            if ($width === null && $height === null) {
                $width = $boxWidth;
                $height = $boxHeight;
            } elseif ($width === null && $boxHeight > 0) {
                $width = $height * $boxWidth / $boxHeight;
            } elseif ($height === null && $boxWidth > 0) {
                $height = $width * $boxHeight / $boxWidth;
            }
        }

        return [$width !== null ? (int) round($width) : null, $height !== null ? (int) round($height) : null];
    }
}
