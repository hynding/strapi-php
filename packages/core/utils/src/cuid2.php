<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of @paralleldrive/cuid2 `createId()` (used for Strapi 5 document ids).
 *
 * Format: one random letter a-z followed by base36 digits of
 * sha3-512(time base36 + salt + counter base36 + fingerprint), truncated to length - 1.
 * Only the format is contractual (`^[a-z][a-z0-9]{23}$` for the default length): the bytes are
 * random by design. hex -> base36 is done with digit-array arithmetic, no ext-gmp needed.
 */
final class Cuid2
{
    public const DEFAULT_LENGTH = 24;
    public const BIG_LENGTH = 32;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz';

    private static ?int $counter = null;

    private static ?string $fingerprint = null;

    public static function createId(int $length = self::DEFAULT_LENGTH): string
    {
        if ($length < 2 || $length > self::BIG_LENGTH) {
            throw new \InvalidArgumentException('cuid2 length must be between 2 and ' . self::BIG_LENGTH);
        }

        $firstLetter = self::ALPHABET[random_int(0, 25)];
        $time = self::toBase36((string) (int) floor(microtime(true) * 1000));
        $count = self::toBase36((string) self::nextCounter());
        $salt = self::createEntropy($length);
        $hashInput = $time . $salt . $count . self::fingerprint();

        return $firstLetter . substr(self::hash($hashInput), 1, $length - 1);
    }

    public static function isCuid(string $id, int $minLength = 2, int $maxLength = self::BIG_LENGTH): bool
    {
        $len = strlen($id);

        return $len >= $minLength && $len <= $maxLength && preg_match('/^[0-9a-z]+$/', $id) === 1;
    }

    /** sha3-512 of the input as a base36 string (upstream drops the first char to even out the distribution). */
    private static function hash(string $input): string
    {
        return self::hexToBase36(hash('sha3-512', $input));
    }

    private static function createEntropy(int $length): string
    {
        $entropy = '';
        while (strlen($entropy) < $length) {
            $entropy .= self::toBase36((string) random_int(0, 35));
        }

        return $entropy;
    }

    private static function nextCounter(): int
    {
        if (self::$counter === null) {
            self::$counter = (int) floor(random_int(0, PHP_INT_MAX >> 12) * 476782367 / (PHP_INT_MAX >> 12));
        }

        return self::$counter++;
    }

    private static function fingerprint(): string
    {
        if (self::$fingerprint === null) {
            $globals = implode(',', array_keys($GLOBALS)) . php_uname() . (string) getmypid();
            self::$fingerprint = substr(self::hash(self::createEntropy(self::BIG_LENGTH) . $globals), 0, self::BIG_LENGTH);
        }

        return self::$fingerprint;
    }

    /** Converts a decimal integer string to base36. */
    private static function toBase36(string $decimal): string
    {
        $digits = array_map('intval', str_split($decimal));

        return self::digitsToBase36($digits, 10);
    }

    /** Converts a hex string to base36 using repeated division on a digit array. */
    private static function hexToBase36(string $hex): string
    {
        $digits = array_map(static fn (string $c): int => (int) hexdec($c), str_split(strtolower($hex)));

        return self::digitsToBase36($digits, 16);
    }

    /**
     * @param list<int> $digits most-significant first, in base $base
     */
    private static function digitsToBase36(array $digits, int $base): string
    {
        $out = '';
        // strip leading zeros
        while (count($digits) > 1 && $digits[0] === 0) {
            array_shift($digits);
        }
        if ($digits === [0] || $digits === []) {
            return '0';
        }

        while ($digits !== []) {
            $remainder = 0;
            $quotient = [];
            foreach ($digits as $digit) {
                $acc = $remainder * $base + $digit;
                $q = intdiv($acc, 36);
                $remainder = $acc % 36;
                if ($quotient !== [] || $q !== 0) {
                    $quotient[] = $q;
                }
            }
            $out = base_convert((string) $remainder, 10, 36) . $out;
            $digits = $quotient;
        }

        return $out;
    }
}
