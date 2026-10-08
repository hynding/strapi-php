<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Stream;

use Strapi\DataTransfer\Utils\Encryption\Cipher;

/**
 * Not an upstream file: the byte pipelines the file providers build with Node streams
 * (`fs.createReadStream`, `zlib.createGunzip()` / `createGzip()`, the cipher) as generators over
 * binary string chunks, and the matching writer chain.
 */
final class Bytes
{
    public const int CHUNK_SIZE = 65536;

    /**
     * @param positive-int $chunkSize
     *
     * @return \Generator<int, string>
     */
    public static function readFile(string $path, int $chunkSize = self::CHUNK_SIZE): \Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("ENOENT: no such file or directory, open '{$path}'");
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new \RuntimeException("Could not read {$path}");
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource     $handle
     * @param positive-int $chunkSize
     *
     * @return \Generator<int, string>
     */
    public static function readHandle(mixed $handle, int $chunkSize = self::CHUNK_SIZE): \Generator
    {
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /**
     * @param iterable<string> $source
     *
     * @return \Generator<int, string>
     */
    public static function cipher(iterable $source, Cipher $cipher): \Generator
    {
        foreach ($source as $chunk) {
            $out = $cipher->update($chunk);
            if ($out !== '') {
                yield $out;
            }
        }

        $out = $cipher->final();
        if ($out !== '') {
            yield $out;
        }
    }

    /**
     * @param iterable<string> $source
     *
     * @return \Generator<int, string>
     */
    public static function gunzip(iterable $source): \Generator
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new \RuntimeException('Could not create the gunzip stream');
        }

        foreach ($source as $chunk) {
            $out = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if ($out === false) {
                throw new \RuntimeException('incorrect header check');
            }
            if ($out !== '') {
                yield $out;
            }
        }

        $out = @inflate_add($context, '', ZLIB_FINISH);
        if ($out === false) {
            throw new \RuntimeException('unexpected end of file');
        }
        if ($out !== '') {
            yield $out;
        }
    }

    /**
     * A writer chain: tar bytes → (gzip) → (cipher) → file. Returns the `[write, close]` pair.
     *
     * @param resource $handle
     *
     * @return array{\Closure(string): void, \Closure(): void}
     */
    public static function createWriter(mixed $handle, bool $gzip, ?Cipher $cipher): array
    {
        $deflate = $gzip ? deflate_init(ZLIB_ENCODING_GZIP) : null;
        if ($deflate === false) {
            throw new \RuntimeException('Could not create the gzip stream');
        }

        $toFile = static function (string $bytes) use ($handle): void {
            if ($bytes === '') {
                return;
            }
            $written = @fwrite($handle, $bytes);
            if ($written === false || $written < strlen($bytes)) {
                $error = error_get_last();
                $message = $error['message'] ?? 'write failed';
                if (stripos($message, 'No space left') !== false) {
                    throw new \RuntimeException('ENOSPC: no space left on device');
                }
                throw new \RuntimeException($message);
            }
        };

        $toCipher = $cipher === null ? $toFile : static function (string $bytes) use ($cipher, $toFile): void {
            $toFile($cipher->update($bytes));
        };

        $write = $deflate === null ? $toCipher : static function (string $bytes) use ($deflate, $toCipher): void {
            $toCipher((string) deflate_add($deflate, $bytes, ZLIB_NO_FLUSH));
        };

        $close = static function () use ($deflate, $toCipher, $toFile, $cipher, $handle): void {
            if ($deflate !== null) {
                $toCipher((string) deflate_add($deflate, '', ZLIB_FINISH));
            }
            if ($cipher !== null) {
                $toFile($cipher->final());
            }
            fclose($handle);
        };

        return [$write, $close];
    }
}
