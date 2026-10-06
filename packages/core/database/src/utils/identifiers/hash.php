<?php

declare(strict_types=1);

namespace Strapi\Database\Utils\Identifiers;

/**
 * Port of packages/core/database/src/utils/identifiers/hash.ts.
 *
 * IMPORTANT: any change to the output of createHash() changes every shortened identifier
 * and therefore makes schema sync drop data it no longer recognises. Node computes
 * `crypto.createHash('shake256', { outputLength: ceil(len / 2) })`; PHP ships SHA3 but not the
 * SHAKE XOFs, so Keccak-f[1600] is implemented here (rate 1088, domain suffix 0x1F).
 */
final class Hash
{
    private const ROUND_CONSTANTS = [
        '0000000000000001', '0000000000008082', '800000000000808A', '8000000080008000',
        '000000000000808B', '0000000080000001', '8000000080008081', '8000000000008009',
        '000000000000008A', '0000000000000088', '0000000080008009', '000000008000000A',
        '000000008000808B', '800000000000008B', '8000000000008089', '8000000000008003',
        '8000000000008002', '8000000000000080', '000000000000800A', '800000008000000A',
        '8000000080008081', '8000000000008080', '0000000080000001', '8000000080008008',
    ];

    /** Rotation offsets indexed by lane (x + 5y). */
    private const ROTATIONS = [
        0, 1, 62, 28, 27,
        36, 44, 6, 55, 20,
        3, 10, 43, 25, 39,
        41, 45, 15, 21, 8,
        18, 2, 61, 56, 14,
    ];

    private const RATE_BYTES = 136; // SHAKE256: (1600 - 2 * 256) / 8

    /** @var list<int>|null */
    private static ?array $rc = null;

    /**
     * Creates a hash of the given data with the specified string length as a string of hex characters.
     *
     * @example createHash("myData", 5) // "03f85"
     */
    public static function createHash(string $data, int $len): string
    {
        if ($len <= 0) {
            throw new \InvalidArgumentException("createHash length must be a positive integer, received {$len}");
        }

        $outputLength = intdiv($len + 1, 2);

        return substr(bin2hex(self::shake256($data, $outputLength)), 0, $len);
    }

    public static function shake256(string $message, int $outputLength): string
    {
        $state = array_fill(0, 25, 0);

        // pad10*1 with the SHAKE domain suffix 0x1F
        $padded = $message . "\x1F";
        $padded .= str_repeat("\0", self::RATE_BYTES - (strlen($padded) % self::RATE_BYTES));
        $padded[strlen($padded) - 1] = chr(ord($padded[strlen($padded) - 1]) | 0x80);

        for ($offset = 0, $total = strlen($padded); $offset < $total; $offset += self::RATE_BYTES) {
            $block = unpack('P17', substr($padded, $offset, self::RATE_BYTES));
            for ($i = 0; $i < 17; $i++) {
                $state[$i] ^= $block[$i + 1];
            }
            self::keccakF($state);
        }

        $output = '';
        while (strlen($output) < $outputLength) {
            for ($i = 0; $i < 17 && strlen($output) < $outputLength; $i++) {
                $output .= pack('P', $state[$i]);
            }
            if (strlen($output) < $outputLength) {
                self::keccakF($state);
            }
        }

        return substr($output, 0, $outputLength);
    }

    /** @return list<int> */
    private static function roundConstants(): array
    {
        if (self::$rc === null) {
            self::$rc = array_map(static fn (string $hex): int => unpack('J', hex2bin($hex))[1], self::ROUND_CONSTANTS);
        }

        return self::$rc;
    }

    private static function rotl(int $x, int $n): int
    {
        if ($n === 0) {
            return $x;
        }

        return ($x << $n) | (($x >> (64 - $n)) & ((1 << $n) - 1));
    }

    /** @param array<int, int> $a */
    private static function keccakF(array &$a): void
    {
        $rc = self::roundConstants();

        for ($round = 0; $round < 24; $round++) {
            // theta
            $c = [];
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = $a[$x] ^ $a[$x + 5] ^ $a[$x + 10] ^ $a[$x + 15] ^ $a[$x + 20];
            }
            for ($x = 0; $x < 5; $x++) {
                $d = $c[($x + 4) % 5] ^ self::rotl($c[($x + 1) % 5], 1);
                for ($y = 0; $y < 25; $y += 5) {
                    $a[$x + $y] ^= $d;
                }
            }

            // rho + pi
            $b = array_fill(0, 25, 0);
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $b[$y + 5 * ((2 * $x + 3 * $y) % 5)] = self::rotl($a[$x + 5 * $y], self::ROTATIONS[$x + 5 * $y]);
                }
            }

            // chi
            for ($y = 0; $y < 25; $y += 5) {
                for ($x = 0; $x < 5; $x++) {
                    $a[$x + $y] = $b[$x + $y] ^ ((~$b[($x + 1) % 5 + $y]) & $b[($x + 2) % 5 + $y]);
                }
            }

            // iota
            $a[0] ^= $rc[$round];
        }
    }
}
