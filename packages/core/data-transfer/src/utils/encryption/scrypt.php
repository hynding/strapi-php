<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Encryption;

/**
 * Not an upstream file: Node's `crypto.scryptSync(password, salt, keylen)` (N = 16384, r = 8,
 * p = 1, RFC 7914) in plain PHP, which has no scrypt of its own (libsodium's requires a 32-byte
 * salt; upstream derives the archive key with an empty one). Derived keys are memoized per
 * process: the file providers re-open the archive once per stage.
 */
final class Scrypt
{
    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * @param positive-int $keylen
     * @param positive-int $n
     * @param positive-int $r
     * @param positive-int $p
     */
    public static function scryptSync(string $password, string $salt, int $keylen, int $n = 16384, int $r = 8, int $p = 1): string
    {
        $cacheKey = hash('sha256', $password) . "|{$salt}|{$keylen}|{$n}|{$r}|{$p}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        if ($n < 2 || ($n & ($n - 1)) !== 0) {
            throw new \InvalidArgumentException('scrypt: N must be a power of 2 greater than 1');
        }

        $blockSize = 128 * $r;
        $b = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockSize, true);

        $out = '';
        for ($i = 0; $i < $p; ++$i) {
            $out .= self::roMix(substr($b, $i * $blockSize, $blockSize), $n, $r);
        }

        return self::$cache[$cacheKey] = hash_pbkdf2('sha256', $password, $out, 1, $keylen, true);
    }

    private static function roMix(string $block, int $n, int $r): string
    {
        $x = self::words($block);
        $words = 32 * $r;
        $v = [];

        for ($i = 0; $i < $n; ++$i) {
            // packed: N blocks of 128 * r bytes (16 MiB) instead of N PHP arrays (~70 MiB)
            $v[$i] = pack('V*', ...$x);
            $x = self::blockMix($x, $r);
        }

        $last = $words - 16;
        for ($i = 0; $i < $n; ++$i) {
            $j = $x[$last] & ($n - 1);
            $vj = self::words($v[$j]);
            for ($k = 0; $k < $words; ++$k) {
                $x[$k] ^= $vj[$k];
            }
            $x = self::blockMix($x, $r);
        }

        return pack('V*', ...$x);
    }

    /**
     * Little-endian 32-bit words of a binary string.
     *
     * @return list<int>
     */
    private static function words(string $bytes): array
    {
        $unpacked = unpack('V*', $bytes);

        return $unpacked === false ? [] : array_values(array_map('intval', $unpacked));
    }

    /**
     * @param array<int, int> $b the words of 2r 64-byte blocks, indexed from 0
     *
     * @return list<int>
     */
    private static function blockMix(array $b, int $r): array
    {
        $blocks = 2 * $r;
        $x = array_slice($b, ($blocks - 1) * 16, 16);
        $even = [];
        $odd = [];

        for ($i = 0; $i < $blocks; ++$i) {
            $offset = $i * 16;
            for ($k = 0; $k < 16; ++$k) {
                $x[$k] ^= $b[$offset + $k];
            }
            $x = self::salsa208($x);
            if (($i & 1) === 0) {
                array_push($even, ...$x);
            } else {
                array_push($odd, ...$x);
            }
        }

        return [...$even, ...$odd];
    }

    /**
     * Salsa20/8 core.
     *
     * @param array<int, int> $in the 16 words of a block, indexed from 0
     *
     * @return list<int>
     */
    private static function salsa208(array $in): array
    {
        [$x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15] = $in;

        for ($i = 0; $i < 4; ++$i) {
                $t = ($x0 + $x12) & 0xffffffff;
                $x4 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x4 + $x0) & 0xffffffff;
                $x8 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x8 + $x4) & 0xffffffff;
                $x12 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x12 + $x8) & 0xffffffff;
                $x0 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x5 + $x1) & 0xffffffff;
                $x9 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x9 + $x5) & 0xffffffff;
                $x13 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x13 + $x9) & 0xffffffff;
                $x1 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x1 + $x13) & 0xffffffff;
                $x5 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x10 + $x6) & 0xffffffff;
                $x14 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x14 + $x10) & 0xffffffff;
                $x2 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x2 + $x14) & 0xffffffff;
                $x6 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x6 + $x2) & 0xffffffff;
                $x10 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x15 + $x11) & 0xffffffff;
                $x3 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x3 + $x15) & 0xffffffff;
                $x7 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x7 + $x3) & 0xffffffff;
                $x11 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x11 + $x7) & 0xffffffff;
                $x15 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x0 + $x3) & 0xffffffff;
                $x1 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x1 + $x0) & 0xffffffff;
                $x2 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x2 + $x1) & 0xffffffff;
                $x3 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x3 + $x2) & 0xffffffff;
                $x0 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x5 + $x4) & 0xffffffff;
                $x6 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x6 + $x5) & 0xffffffff;
                $x7 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x7 + $x6) & 0xffffffff;
                $x4 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x4 + $x7) & 0xffffffff;
                $x5 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x10 + $x9) & 0xffffffff;
                $x11 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x11 + $x10) & 0xffffffff;
                $x8 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x8 + $x11) & 0xffffffff;
                $x9 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x9 + $x8) & 0xffffffff;
                $x10 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
                $t = ($x15 + $x14) & 0xffffffff;
                $x12 ^= (($t << 7) & 0xffffffff) | ($t >> 25);
                $t = ($x12 + $x15) & 0xffffffff;
                $x13 ^= (($t << 9) & 0xffffffff) | ($t >> 23);
                $t = ($x13 + $x12) & 0xffffffff;
                $x14 ^= (($t << 13) & 0xffffffff) | ($t >> 19);
                $t = ($x14 + $x13) & 0xffffffff;
                $x15 ^= (($t << 18) & 0xffffffff) | ($t >> 14);
        }

        return [
            ($x0 + $in[0]) & 0xffffffff, ($x1 + $in[1]) & 0xffffffff, ($x2 + $in[2]) & 0xffffffff, ($x3 + $in[3]) & 0xffffffff,
            ($x4 + $in[4]) & 0xffffffff, ($x5 + $in[5]) & 0xffffffff, ($x6 + $in[6]) & 0xffffffff, ($x7 + $in[7]) & 0xffffffff,
            ($x8 + $in[8]) & 0xffffffff, ($x9 + $in[9]) & 0xffffffff, ($x10 + $in[10]) & 0xffffffff, ($x11 + $in[11]) & 0xffffffff,
            ($x12 + $in[12]) & 0xffffffff, ($x13 + $in[13]) & 0xffffffff, ($x14 + $in[14]) & 0xffffffff, ($x15 + $in[15]) & 0xffffffff,
        ];
    }
}
