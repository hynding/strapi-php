<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/file.ts (file treatment utils).
 *
 * Node streams map to PHP as follows (no Composer dependency is added):
 *
 * - a readable stream is a PHP stream `resource`, a PSR-7 `StreamInterface` (detected
 *   structurally: any object with `eof()` and `read(int)`), or an `iterable` of chunks
 *   (the equivalent of `Readable.from([...])`; a generator that throws plays the role of a
 *   stream emitting `error`). Chunks may be strings or lists of byte values (`Uint8Array`);
 * - a `Buffer` is a PHP binary string;
 * - the promise resolves synchronously: the helpers return the value, and the stream's error
 *   is thrown as is (upstream rejects with the original error).
 *
 * @phpstan-type ReadableStream resource|object|iterable<string|list<int>>
 */
final class File
{
    public static function kbytesToBytes(int|float $kbytes): int|float
    {
        return $kbytes * 1000;
    }

    public static function bytesToKbytes(int|float $bytes): float
    {
        return round(($bytes / 1000) * 100) / 100;
    }

    public static function bytesToHumanReadable(int|float $bytes): string
    {
        $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
        if ($bytes == 0) {
            return '0 Bytes';
        }
        $i = (int) floor(log($bytes) / log(1000));
        $value = round($bytes / 1000 ** $i);

        return sprintf('%s %s', PrintValue::printValue($value), $sizes[$i] ?? 'undefined');
    }

    /**
     * Reads the whole stream into a binary string (upstream: a `Buffer`).
     *
     * @param ReadableStream $stream
     */
    public static function streamToBuffer(mixed $stream): string
    {
        $buffer = '';
        foreach (self::chunks($stream) as $chunk) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Consumes the stream and returns its size in bytes.
     *
     * @param ReadableStream $stream
     */
    public static function getStreamSize(mixed $stream): int
    {
        $size = 0;
        foreach (self::chunks($stream) as $chunk) {
            $size += strlen($chunk);
        }

        return $size;
    }

    /**
     * A writable stream that discards received data (upstream: a no-op `Writable`). Useful for
     * testing, draining a stream of data, etc. Upstream's `WritableOptions` have no PHP
     * counterpart and are not accepted.
     *
     * @return resource
     */
    public static function writableDiscardStream()
    {
        $handle = fopen(PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null', 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open a discard stream');
        }

        return $handle;
    }

    /**
     * @param ReadableStream $stream
     * @return \Generator<int, string>
     */
    private static function chunks(mixed $stream): \Generator
    {
        if (is_resource($stream)) {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException('Unable to read from stream');
                }
                yield $chunk;
            }

            return;
        }

        if (is_iterable($stream)) {
            foreach ($stream as $chunk) {
                yield is_array($chunk) ? pack('C*', ...$chunk) : (string) $chunk;
            }

            return;
        }

        if (is_object($stream) && method_exists($stream, 'eof') && method_exists($stream, 'read')) {
            while (!$stream->eof()) {
                yield (string) $stream->read(8192);
            }

            return;
        }

        throw new \InvalidArgumentException('Expected a stream resource, a PSR-7 stream or an iterable of chunks');
    }
}
