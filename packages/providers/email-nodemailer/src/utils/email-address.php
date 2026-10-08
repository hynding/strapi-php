<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Utils;

/**
 * Port of src/utils/email-address.ts.
 *
 * RFC-compliant Email Address Utilities for Nodemailer Provider
 *
 * Provides utilities for parsing and formatting email addresses
 * according to RFC 5322, RFC 2047, and related standards.
 *
 * @phpstan-type ParsedEmailAddress array{name: string|null, email: string, original: string}
 */
final class EmailAddress
{
    /**
     * Normalizes an email address to lowercase.
     *
     * Per RFC 5321 section 2.4, the domain part of an email address is
     * case-insensitive. While the local part is technically case-sensitive,
     * in practice virtually all mail systems treat it as case-insensitive.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc5321#section-2.4
     */
    public static function normalizeEmail(string $email): string
    {
        if ($email === '') {
            return $email;
        }

        return mb_strtolower($email, 'UTF-8');
    }

    /**
     * Decodes RFC 2047 encoded-words
     * Supports both Base64 (B) and Quoted-Printable (Q) encodings
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2047
     */
    public static function decodeRfc2047(string $encoded): string
    {
        $rfc2047Pattern = '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/';

        return (string) preg_replace_callback($rfc2047Pattern, static function (array $m): string {
            $upperEncoding = strtoupper($m[2]);
            $text = $m[3];

            if ($upperEncoding === 'B') {
                return self::utf8(base64_decode(strtr($text, '-_', '+/'), false) ?: '');
            }

            if ($upperEncoding === 'Q') {
                $withSpaces = str_replace('_', ' ', $text);
                $decoded = (string) preg_replace_callback(
                    '/=([0-9A-Fa-f]{2})/',
                    static fn (array $h): string => chr((int) hexdec($h[1])),
                    $withSpaces,
                );

                return self::utf8($decoded);
            }

            return $m[0];
        }, $encoded);
    }

    /**
     * Encodes a string as RFC 2047 Base64 encoded-word
     * Use this when the display name contains non-ASCII characters
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2047
     */
    public static function encodeRfc2047Base64(string $str): string
    {
        if (preg_match('/[^\x00-\x7F]/', $str) !== 1) {
            return $str;
        }

        $bytes = $str;
        $length = strlen($bytes);
        // RFC 2047 Section 2: encoded-word max 75 chars total
        // =?UTF-8?B?...?= overhead is 12 chars, leaving 63 for base64 payload
        // 63 base64 chars encode 47 bytes (floor(63 * 3 / 4) = 47)
        $maxBytesPerChunk = 45; // conservative (produces 60 base64 chars = 72 total)

        if ($length <= $maxBytesPerChunk) {
            return '=?UTF-8?B?' . base64_encode($bytes) . '?=';
        }

        $parts = [];
        $offset = 0;

        while ($offset < $length) {
            $chunkEnd = min($offset + $maxBytesPerChunk, $length);

            // Avoid splitting in the middle of a multi-byte UTF-8 character
            // UTF-8 continuation bytes start with 10xxxxxx (0x80-0xBF)
            while ($chunkEnd < $length && ord($bytes[$chunkEnd]) >= 0x80 && ord($bytes[$chunkEnd]) < 0xC0) {
                $chunkEnd--;
            }

            $parts[] = '=?UTF-8?B?' . base64_encode(substr($bytes, $offset, $chunkEnd - $offset)) . '?=';
            $offset = $chunkEnd;
        }

        return implode(' ', $parts);
    }

    /** Encodes a string as RFC 2047 Quoted-Printable encoded-word */
    public static function encodeRfc2047QuotedPrintable(string $str): string
    {
        // Check if encoding is needed
        if (preg_match('/[^\x00-\x7F]/', $str) !== 1) {
            return $str;
        }

        $result = '';
        foreach (str_split($str) as $char) {
            $byte = ord($char);
            // Printable ASCII (except = ? _ and space)
            if (($byte >= 33 && $byte <= 126 && $byte !== 61 && $byte !== 63 && $byte !== 95) || $byte === 32) {
                $result .= $byte === 32 ? '_' : $char;
            } else {
                $result .= '=' . str_pad(strtoupper(dechex($byte)), 2, '0', STR_PAD_LEFT);
            }
        }

        return "=?UTF-8?Q?{$result}?=";
    }

    /**
     * Extracts RFC 5322 comments from an email string
     * Comments are enclosed in parentheses
     *
     * @return array{text: string, comments: list<string>}
     */
    public static function extractComments(string $str): array
    {
        $comments = [];
        $result = '';
        $depth = 0;
        $currentComment = '';
        $inQuotes = false;
        $escape = false;

        foreach (self::chars($str) as $char) {
            if ($escape) {
                if ($depth > 0) {
                    $currentComment .= $char;
                } else {
                    $result .= $char;
                }
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
                if ($depth > 0) {
                    $currentComment .= $char;
                } else {
                    $result .= $char;
                }
            } elseif ($char === '"' && $depth === 0) {
                $inQuotes = !$inQuotes;
                $result .= $char;
            } elseif (!$inQuotes && $char === '(') {
                if ($depth === 0) {
                    $currentComment = '';
                } else {
                    $currentComment .= $char;
                }
                $depth++;
            } elseif (!$inQuotes && $char === ')') {
                if ($depth <= 0) {
                    $result .= $char;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $comments[] = trim($currentComment);
                        $currentComment = '';
                    } else {
                        $currentComment .= $char;
                    }
                }
            } elseif ($depth > 0) {
                $currentComment .= $char;
            } else {
                $result .= $char;
            }
        }

        return ['text' => trim($result), 'comments' => $comments];
    }

    /** Unquotes a RFC 5322 quoted string */
    public static function unquoteString(string $str): string
    {
        $trimmed = trim($str);

        if (!str_starts_with($trimmed, '"') || !str_ends_with($trimmed, '"')) {
            return $trimmed;
        }

        $inner = substr($trimmed, 1, -1);
        $result = '';
        $escape = false;

        foreach (self::chars($inner) as $char) {
            if ($escape) {
                $result .= $char;
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Parses an email address string according to RFC 5322
     *
     * Supported formats:
     * - Simple: "email@example.com"
     * - With name: "Name <email@example.com>"
     * - Quoted name: "\"Doe, John\" <email@example.com>"
     * - RFC 2047 encoded: "=?UTF-8?B?...?= <email@example.com>"
     * - With comment: "email@example.com (Display Name)"
     *
     * @return ParsedEmailAddress
     */
    public static function parseEmailAddress(?string $emailString): array
    {
        if ($emailString === null || $emailString === '') {
            return ['name' => null, 'email' => '', 'original' => $emailString ?? ''];
        }

        $original = $emailString;

        // Step 1: Decode RFC 2047 encoded-words
        $decoded = self::decodeRfc2047($emailString);

        // Step 2: Extract comments
        ['text' => $withoutComments, 'comments' => $comments] = self::extractComments($decoded);
        $decoded = $withoutComments;

        // Step 3: Parse "Name <email>" format
        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/su', $decoded, $angleMatch) === 1) {
            $name = trim($angleMatch[1]);
            $email = self::normalizeEmail(trim($angleMatch[2]));

            if ($name !== '') {
                $name = self::unquoteString($name);
                $name = self::decodeRfc2047($name);
            }

            if ($name === '' && count($comments) > 0) {
                $name = $comments[0];
            }

            return [
                'name' => $name !== '' ? $name : null,
                'email' => $email,
                'original' => $original,
            ];
        }

        // Step 4: Handle "email (comment)" format
        $trimmedDecoded = trim($decoded);

        if (str_contains($trimmedDecoded, '@')) {
            return [
                'name' => count($comments) > 0 ? $comments[0] : null,
                'email' => self::normalizeEmail($trimmedDecoded),
                'original' => $original,
            ];
        }

        return [
            'name' => null,
            'email' => $trimmedDecoded,
            'original' => $original,
        ];
    }

    /**
     * Formats an email address according to RFC 5322
     *
     * Automatically quotes names containing special characters
     * and encodes non-ASCII characters using RFC 2047.
     *
     * @param array{encodeNonAscii?: bool} $options
     */
    public static function formatEmailAddress(?string $name, string $email, array $options = []): string
    {
        $encodeNonAscii = $options['encodeNonAscii'] ?? true;
        $normalizedEmail = self::normalizeEmail($email);

        if ($name === null || $name === '') {
            return $normalizedEmail;
        }

        $formattedName = $name;

        // Check for non-ASCII characters and encode if needed
        if ($encodeNonAscii && preg_match('/[^\x00-\x7F]/', $name) === 1) {
            $formattedName = self::encodeRfc2047Base64($name);

            return "{$formattedName} <{$normalizedEmail}>";
        }

        // Check if name needs quoting (special characters)
        $needsQuoting = preg_match('/[()<>@,;:\\\\".\[\]]/', $formattedName) === 1;

        if ($needsQuoting && !str_starts_with($formattedName, '"')) {
            $escaped = str_replace('"', '\\"', str_replace('\\', '\\\\', $formattedName));

            return "\"{$escaped}\" <{$normalizedEmail}>";
        }

        return "{$formattedName} <{$normalizedEmail}>";
    }

    /** Validates an email address according to RFC 5322 */
    public static function isValidEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        // RFC 5321 length limits: local max 64, domain max 255, total max 320
        $atIndex = strpos($email, '@');
        if ($atIndex === false) {
            return false;
        }
        $local = substr($email, 0, $atIndex);
        $localLength = self::jsLength($local);
        if ($localLength < 1 || $localLength > 64) {
            return false;
        }

        $domain = substr($email, $atIndex + 1);
        $domainLength = self::jsLength($domain);
        if ($domainLength === 0 || $domainLength > 255) {
            return false;
        }
        if (self::jsLength($email) > 320) {
            return false;
        }

        // No leading/trailing/consecutive dots in the local part (RFC 5321)
        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return false;
        }

        // `\u0080-￿` over UTF-16 code units also accepts astral characters (surrogate pairs)
        $u = '\x{0080}-\x{10FFFF}';
        $emailPattern = "/^[a-zA-Z0-9.!#$%&'*+\\/=?^_`{|}~{$u}-]+@[a-zA-Z0-9{$u}](?:[a-zA-Z0-9{$u}-]{0,61}[a-zA-Z0-9{$u}])?(?:\\.[a-zA-Z0-9{$u}](?:[a-zA-Z0-9{$u}-]{0,61}[a-zA-Z0-9{$u}])?)*$/u";

        return preg_match($emailPattern, $email) === 1;
    }

    /**
     * Parses multiple comma-separated email addresses
     *
     * @return list<ParsedEmailAddress>
     */
    public static function parseMultipleEmailAddresses(?string $emailsString): array
    {
        if ($emailsString === null || $emailsString === '') {
            return [];
        }

        $addresses = [];
        $current = '';
        $inQuotes = false;
        $depth = 0;
        $escaped = false;

        foreach (self::chars($emailsString) as $char) {
            if ($escaped) {
                $current .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
                $current .= $char;
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
                $current .= $char;
            } elseif (!$inQuotes && ($char === '<' || $char === '(')) {
                $depth++;
                $current .= $char;
            } elseif (!$inQuotes && ($char === '>' || $char === ')')) {
                $depth--;
                $current .= $char;
            } elseif (!$inQuotes && $char === ',' && $depth === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $addresses[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $addresses[] = $trimmed;
        }

        return array_map(self::parseEmailAddress(...), $addresses);
    }

    /** @return list<string> the string's code points (`for (const char of str)`) */
    private static function chars(string $str): array
    {
        $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? str_split($str) : $chars;
    }

    /** `Buffer.toString('utf-8')`: invalid sequences become U+FFFD. */
    private static function utf8(string $bytes): string
    {
        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_scrub($bytes, 'UTF-8');
    }

    /** `str.length` (UTF-16 code units). */
    private static function jsLength(string $str): int
    {
        return intdiv(strlen((string) mb_convert_encoding($str, 'UTF-16LE', 'UTF-8')), 2);
    }
}
