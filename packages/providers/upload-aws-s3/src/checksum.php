<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3;

/**
 * PHP-port addition: the flexible checksums the AWS SDK computes for `ChecksumAlgorithm`
 * (`@aws-sdk/middleware-flexible-checksums`): base64 of the big-endian digest, sent as
 * `x-amz-checksum-<algorithm>`.
 */
final class Checksum
{
    public const array ALGORITHMS = ['CRC32', 'CRC32C', 'SHA1', 'SHA256', 'CRC64NVME'];

    /** @var list<int>|null */
    private static ?array $crc64Table = null;

    /** Header name for an algorithm: `x-amz-checksum-crc32`. */
    public static function header(string $algorithm): string
    {
        return 'x-amz-checksum-' . strtolower($algorithm);
    }

    /** XML element name in CompleteMultipartUpload parts: `ChecksumCRC32`. */
    public static function element(string $algorithm): string
    {
        return 'Checksum' . strtoupper($algorithm);
    }

    public static function compute(string $algorithm, string $data): string
    {
        $raw = match (strtoupper($algorithm)) {
            'CRC32' => hash('crc32b', $data, true),
            'CRC32C' => hash('crc32c', $data, true),
            'SHA1' => hash('sha1', $data, true),
            'SHA256' => hash('sha256', $data, true),
            'CRC64NVME' => pack('J', self::crc64nvme($data)),
            default => throw new \InvalidArgumentException("Unsupported checksum algorithm: {$algorithm}"),
        };

        return base64_encode($raw);
    }

    /**
     * CRC-64/NVME (reflected polynomial 0x9a6c9329ac4bc9b5, init and xorout all ones), which PHP's
     * `hash()` doesn't provide. Table-driven, one byte at a time.
     */
    public static function crc64nvme(string $data): int
    {
        $table = self::$crc64Table ??= self::crc64Table();
        $crc = ~0;
        $length = strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            $crc = $table[($crc ^ ord($data[$i])) & 0xFF] ^ (($crc >> 8) & 0x00FFFFFFFFFFFFFF);
        }

        return ~$crc;
    }

    /** @return list<int> */
    private static function crc64Table(): array
    {
        $poly = (0x9A6C9329 << 32) | 0xAC4BC9B5;
        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $crc = $i;
            for ($bit = 0; $bit < 8; ++$bit) {
                $crc = ($crc & 1) !== 0 ? (($crc >> 1) & PHP_INT_MAX) ^ $poly : ($crc >> 1) & PHP_INT_MAX;
            }
            $table[] = $crc;
        }

        return $table;
    }
}
