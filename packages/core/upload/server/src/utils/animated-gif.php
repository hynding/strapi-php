<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

/**
 * PHP-port addition: resizing an animated GIF frame by frame, standing in for sharp's
 * `{ animated: true }` (GD alone reads and writes the first frame only).
 *
 * Frames are composited on the logical screen (honouring each frame's offset, transparency and
 * disposal method) as libvips does, each full frame is resized, and the frames are written back
 * with their delays and the original loop count.
 */
final class AnimatedGif
{
    /**
     * @return list<array{data: string, delay: int, disposal: int, transparent: int|null, left: int, top: int, width: int, height: int, localTable: string|null, interlaced: bool}>|null
     *   the frames, or null when the data is not a GIF with more than one frame
     */
    private static function parse(string $data, ?string &$globalTable, ?string &$loop, int &$screenWidth, int &$screenHeight, int &$background): ?array
    {
        if (strlen($data) < 13 || !in_array(substr($data, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return null;
        }

        $screenWidth = ord($data[6]) | (ord($data[7]) << 8);
        $screenHeight = ord($data[8]) | (ord($data[9]) << 8);
        $flags = ord($data[10]);
        $background = ord($data[11]);
        $pos = 13;
        $globalTable = null;
        if ($flags & 0x80) {
            $size = 3 * (2 << ($flags & 0x07));
            $globalTable = substr($data, $pos, $size);
            $pos += $size;
        }

        $frames = [];
        $gce = ['delay' => 0, 'disposal' => 0, 'transparent' => null];
        $loop = null;
        $len = strlen($data);

        while ($pos < $len) {
            $byte = ord($data[$pos]);
            if ($byte === 0x3B) {
                break;
            }
            if ($byte === 0x21) {
                $label = ord($data[$pos + 1] ?? "\0");
                $start = $pos;
                $pos += 2;
                $blocks = '';
                while ($pos < $len && ($blockSize = ord($data[$pos])) !== 0) {
                    $blocks .= substr($data, $pos + 1, $blockSize);
                    $pos += $blockSize + 1;
                }
                $pos++;
                if ($label === 0xF9 && strlen($blocks) >= 4) {
                    $packed = ord($blocks[0]);
                    $gce = [
                        'delay' => ord($blocks[1]) | (ord($blocks[2]) << 8),
                        'disposal' => ($packed >> 2) & 0x07,
                        'transparent' => ($packed & 0x01) ? ord($blocks[3]) : null,
                    ];
                } elseif ($label === 0xFF && str_starts_with($blocks, 'NETSCAPE2.0')) {
                    $loop = substr($data, $start, $pos - $start);
                }
                continue;
            }
            if ($byte !== 0x2C) {
                return null;
            }

            $left = ord($data[$pos + 1]) | (ord($data[$pos + 2]) << 8);
            $top = ord($data[$pos + 3]) | (ord($data[$pos + 4]) << 8);
            $width = ord($data[$pos + 5]) | (ord($data[$pos + 6]) << 8);
            $height = ord($data[$pos + 7]) | (ord($data[$pos + 8]) << 8);
            $imageFlags = ord($data[$pos + 9]);
            $pos += 10;
            $localTable = null;
            if ($imageFlags & 0x80) {
                $size = 3 * (2 << ($imageFlags & 0x07));
                $localTable = substr($data, $pos, $size);
                $pos += $size;
            }
            $dataStart = $pos;
            $pos++; // LZW minimum code size
            while ($pos < $len && ($blockSize = ord($data[$pos])) !== 0) {
                $pos += $blockSize + 1;
            }
            $pos++;

            $frames[] = [
                'data' => substr($data, $dataStart, $pos - $dataStart),
                'delay' => $gce['delay'],
                'disposal' => $gce['disposal'],
                'transparent' => $gce['transparent'],
                'left' => $left,
                'top' => $top,
                'width' => $width,
                'height' => $height,
                'localTable' => $localTable,
                'interlaced' => (bool) ($imageFlags & 0x40),
            ];
            $gce = ['delay' => 0, 'disposal' => 0, 'transparent' => null];
        }

        return count($frames) > 1 ? $frames : null;
    }

    /** Whether the GIF has more than one frame. */
    public static function isAnimated(string $data): bool
    {
        $globalTable = null;
        $loop = null;
        $w = $h = $bg = 0;

        return self::parse($data, $globalTable, $loop, $w, $h, $bg) !== null;
    }

    /**
     * Resizes every frame to `$width` x `$height`; null when the data is not an animated GIF.
     */
    public static function resize(string $data, int $width, int $height): ?string
    {
        $globalTable = null;
        $loop = null;
        $screenWidth = $screenHeight = $background = 0;
        $frames = self::parse($data, $globalTable, $loop, $screenWidth, $screenHeight, $background);
        if ($frames === null || $screenWidth < 1 || $screenHeight < 1) {
            return null;
        }

        $canvas = self::transparentCanvas($screenWidth, $screenHeight);
        $out = 'GIF89a' . pack('vv', $width, $height) . "\x00\x00\x00" . ($loop ?? "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00");

        foreach ($frames as $frame) {
            $previous = $frame['disposal'] === 3 ? self::copy($canvas) : null;

            $frameImage = self::decodeFrame($frame, $globalTable);
            if ($frameImage === null) {
                return null;
            }
            imagealphablending($canvas, true);
            imagecopy($canvas, $frameImage, $frame['left'], $frame['top'], 0, 0, $frame['width'], $frame['height']);

            // resize the full composited frame
            $resized = self::transparentCanvas($width, $height);
            imagecopyresampled($resized, $canvas, 0, 0, 0, 0, $width, $height, $screenWidth, $screenHeight);
            $out .= self::encodeFrame($resized, $frame['delay']);

            // dispose
            if ($frame['disposal'] === 2) {
                imagealphablending($canvas, false);
                $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                if ($clear !== false) {
                    imagefilledrectangle($canvas, $frame['left'], $frame['top'], $frame['left'] + $frame['width'] - 1, $frame['top'] + $frame['height'] - 1, $clear);
                }
            } elseif ($previous !== null) {
                $canvas = $previous;
            }
        }

        return $out . ';';
    }

    private static function transparentCanvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($image === false) {
            throw new \RuntimeException('Cannot allocate image');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($image, 0, 0, $transparent);
        }

        return $image;
    }

    private static function copy(\GdImage $image): \GdImage
    {
        $copy = self::transparentCanvas(imagesx($image), imagesy($image));
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }

    /** @param array{data: string, transparent: int|null, width: int, height: int, localTable: string|null, interlaced?: bool} $frame */
    private static function decodeFrame(array $frame, ?string $globalTable): ?\GdImage
    {
        $table = $frame['localTable'] ?? $globalTable;
        if ($table === null) {
            return null;
        }
        $bits = (int) log(strlen($table) / 3, 2) - 1;
        $gif = 'GIF89a' . pack('vv', $frame['width'], $frame['height']) . chr(0x80 | $bits) . "\x00\x00" . $table;
        if ($frame['transparent'] !== null) {
            $gif .= "\x21\xF9\x04\x01\x00\x00" . chr($frame['transparent']) . "\x00";
        }
        $gif .= "\x2C" . pack('vvvv', 0, 0, $frame['width'], $frame['height']) . chr(!empty($frame['interlaced']) ? 0x40 : 0x00) . $frame['data'] . ';';

        $image = @imagecreatefromstring($gif);

        return $image instanceof \GdImage ? $image : null;
    }

    /** One frame (graphic control extension + image) of a resized animation. */
    private static function encodeFrame(\GdImage $frame, int $delay): string
    {
        // palette with a dedicated transparent entry
        $palette = imagecreatetruecolor(imagesx($frame), imagesy($frame));
        if ($palette === false) {
            throw new \RuntimeException('Cannot allocate image');
        }
        $key = imagecolorallocate($palette, 255, 0, 255);
        if ($key !== false) {
            imagefill($palette, 0, 0, $key);
        }
        imagealphablending($palette, true);
        imagecopy($palette, $frame, 0, 0, 0, 0, imagesx($frame), imagesy($frame));
        // pixels still mostly transparent in the source stay transparent
        for ($x = 0, $w = imagesx($frame); $x < $w; $x++) {
            for ($y = 0, $h = imagesy($frame); $y < $h; $y++) {
                if (((imagecolorat($frame, $x, $y) >> 24) & 0x7F) > 63 && $key !== false) {
                    imagesetpixel($palette, $x, $y, $key);
                }
            }
        }
        imagetruecolortopalette($palette, false, 255);
        $transparentIndex = imagecolorclosest($palette, 255, 0, 255);
        imagecolortransparent($palette, $transparentIndex);

        ob_start();
        imagegif($palette);
        $gif = (string) ob_get_clean();

        // lift the color table and image data out of GD's single-frame GIF
        $flags = ord($gif[10]);
        $pos = 13;
        $table = '';
        if ($flags & 0x80) {
            $size = 3 * (2 << ($flags & 0x07));
            $table = substr($gif, $pos, $size);
            $pos += $size;
        }
        while ($pos < strlen($gif) && ord($gif[$pos]) === 0x21) {
            $pos += 2;
            while (($blockSize = ord($gif[$pos])) !== 0) {
                $pos += $blockSize + 1;
            }
            $pos++;
        }
        $descriptor = substr($gif, $pos, 10);
        $rest = substr($gif, $pos + 10, -1); // LZW data up to the trailer

        $imageFlags = (ord($descriptor[9]) & 0x40) | 0x80 | ($flags & 0x07);

        return "\x21\xF9\x04" . chr((1 << 2) | 0x01) . pack('v', $delay) . chr($transparentIndex) . "\x00"
            . substr($descriptor, 0, 9) . chr($imageFlags) . $table . $rest;
    }
}
