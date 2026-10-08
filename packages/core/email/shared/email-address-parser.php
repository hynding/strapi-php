<?php

declare(strict_types=1);

namespace Strapi\Email\Shared;

/**
 * Port of packages/core/email/shared/email-address-parser.ts: RFC-compliant email address parser.
 *
 * Supports:
 * - RFC 5322: Internet Message Format (basic name <email> format)
 * - RFC 2047: MIME encoded-words for non-ASCII characters
 * - RFC 5322: Comments in parentheses
 * - RFC 5322: Quoted strings with special characters
 * - RFC 6531: Internationalized email addresses (UTF-8)
 *
 * Strings are UTF-8; iteration is per code point like JS `for...of`.
 *
 * @phpstan-type ParsedEmailAddress array{name: string|null, email: string, original: string}
 */
final class EmailAddressParser
{
    /**
     * Decodes RFC 2047 encoded-words, Base64 (B) and Quoted-Printable (Q).
     *
     * - =?UTF-8?B?U3RyYXBp?= -> "Strapi"
     * - =?UTF-8?Q?M=C3=BCller?= -> "Müller"
     * - =?ISO-8859-1?Q?=E4=F6=FC?= -> "äöü"
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2047
     */
    private static function decodeRfc2047(string $encoded): string
    {
        // Pattern: =?charset?encoding?encoded_text?=
        return (string) preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', static function (array $m): string {
            [$match, $charset, $encoding, $text] = $m;
            $upperEncoding = strtoupper($encoding);

            if ($upperEncoding === 'B') {
                // Base64 decoding (atob throws on invalid input: keep the original)
                $decoded = base64_decode($text, true);

                return $decoded === false ? $match : self::decodeWithCharset($decoded, $charset);
            }

            // Q: underscores represent spaces, then =XX hex sequences
            $withSpaces = str_replace('_', ' ', $text);
            $decoded = (string) preg_replace_callback('/=([0-9A-Fa-f]{2})/', static fn (array $h): string => chr((int) hexdec($h[1])), $withSpaces);

            return self::decodeWithCharset($decoded, $charset);
        }, $encoded);
    }

    /** Decodes a byte string from a charset; falls back to Latin-1 (JS: the byte-per-char string). */
    private static function decodeWithCharset(string $bytes, string $charset): string
    {
        $upperCharset = strtoupper($charset);
        if ($upperCharset === 'UTF-8' || $upperCharset === 'UTF8') {
            // TextDecoder replaces malformed sequences
            return mb_scrub($bytes, 'UTF-8');
        }

        if ($upperCharset !== 'ISO-8859-1' && $upperCharset !== 'LATIN1' && $upperCharset !== 'LATIN-1') {
            try {
                return mb_convert_encoding($bytes, 'UTF-8', $charset);
            } catch (\ValueError) {
                // unknown charset: fall through to the byte-per-char string
            }
        }

        // ISO-8859-1 (Latin-1): characters map directly
        return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Removes RFC 5322 comments (parentheses, nestable) and returns them.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.2.2
     * @return array{text: string, comments: list<string>}
     */
    private static function extractComments(string $str): array
    {
        $comments = [];
        $result = '';
        $depth = 0;
        $currentComment = '';
        $inQuotes = false;
        $escape = false;

        foreach (mb_str_split($str) as $char) {
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
                $depth += 1;
            } elseif (!$inQuotes && $char === ')') {
                $depth -= 1;
                if ($depth === 0) {
                    $comments[] = self::trim($currentComment);
                    $currentComment = '';
                } elseif ($depth > 0) {
                    $currentComment .= $char;
                }
            } elseif ($depth > 0) {
                $currentComment .= $char;
            } else {
                $result .= $char;
            }
        }

        return ['text' => self::trim($result), 'comments' => $comments];
    }

    /**
     * Unquotes an RFC 5322 quoted string (\" -> ", \\ -> \).
     *
     * @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.2.4
     */
    private static function unquoteString(string $str): string
    {
        $trimmed = self::trim($str);

        if (!str_starts_with($trimmed, '"') || !str_ends_with($trimmed, '"')) {
            return $trimmed;
        }

        // Remove surrounding quotes (`slice(1, -1)`)
        $inner = strlen($trimmed) >= 2 ? substr($trimmed, 1, -1) : '';

        $result = '';
        $escape = false;
        foreach (mb_str_split($inner) as $char) {
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
     * Parses an email address string according to RFC 5322 and related RFCs.
     *
     * 1. "email@example.com"  2. "Name <email@example.com>"  3. "\"Doe, John\" <email@example.com>"
     * 4. "=?UTF-8?B?...?= <email@example.com>"  5. "email@example.com (Display Name)"
     * 6. "\"Name\" <email@example.com> (comment)"
     *
     * @return ParsedEmailAddress
     */
    public static function parseEmailAddress(mixed $emailString): array
    {
        if (!is_string($emailString) || $emailString === '') {
            return ['name' => null, 'email' => '', 'original' => is_string($emailString) ? $emailString : ''];
        }

        $original = $emailString;

        // Step 1: Decode any RFC 2047 encoded-words
        $decoded = self::decodeRfc2047($emailString);

        // Step 2: Extract and remove comments, but save them
        ['text' => $decoded, 'comments' => $comments] = self::extractComments($decoded);

        // Step 3: Try to parse "Name <email>" format (quoted and unquoted names)
        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/u', $decoded, $angleMatch) === 1) {
            $name = self::trim($angleMatch[1]);
            $email = self::trim($angleMatch[2]);

            if ($name !== '') {
                $name = self::unquoteString($name);
                // Decode again in case the name itself contains encoded words
                $name = self::decodeRfc2047($name);
            }

            // If no name in the main part, check comments
            if ($name === '' && $comments !== []) {
                $name = $comments[0];
            }

            return ['name' => $name !== '' ? $name : null, 'email' => $email, 'original' => $original];
        }

        // Step 4: "email (comment)" format - use comment as name
        $trimmedDecoded = self::trim($decoded);

        if (str_contains($trimmedDecoded, '@')) {
            return ['name' => $comments !== [] ? $comments[0] : null, 'email' => $trimmedDecoded, 'original' => $original];
        }

        // Step 5: If nothing matched, treat the whole thing as email
        return ['name' => null, 'email' => $trimmedDecoded, 'original' => $original];
    }

    /**
     * Formats an email address according to RFC 5322 (quotes names with specials or spaces).
     */
    public static function formatEmailAddress(?string $name, string $email): string
    {
        if ($name === null || $name === '') {
            return $email;
        }

        // RFC 5322 specials: ()<>@,;:\".[]
        $needsQuoting = preg_match('/[()<>@,;:\\\\".[\]]/', $name) === 1 || str_contains($name, ' ');

        if ($needsQuoting && !str_starts_with($name, '"')) {
            $escaped = str_replace('"', '\\"', str_replace('\\', '\\\\', $name));

            return "\"{$escaped}\" <{$email}>";
        }

        return "{$name} <{$email}>";
    }

    /** Simplified RFC 5322 validation, internationalized domains allowed (RFC 6531). */
    public static function isValidEmail(mixed $email): bool
    {
        if (!is_string($email) || $email === '') {
            return false;
        }

        $u = '\x{80}-\x{10FFFF}';
        $pattern = "/^[a-zA-Z0-9.!#$%&'*+\\/=?^_`{|}~{$u}-]+@[a-zA-Z0-9{$u}](?:[a-zA-Z0-9{$u}-]{0,61}[a-zA-Z0-9{$u}])?(?:\\.[a-zA-Z0-9{$u}](?:[a-zA-Z0-9{$u}-]{0,61}[a-zA-Z0-9{$u}])?)*$/u";

        return preg_match($pattern, $email) === 1;
    }

    /**
     * Parses comma-separated addresses; commas inside quotes, `<>` or `()` do not split.
     *
     * @return list<ParsedEmailAddress>
     */
    public static function parseMultipleEmailAddresses(mixed $emailsString): array
    {
        if (!is_string($emailsString) || $emailsString === '') {
            return [];
        }

        $addresses = [];
        $current = '';
        $inQuotes = false;
        $depth = 0;
        $prevChar = '';

        foreach (mb_str_split($emailsString) as $char) {
            if ($prevChar === '\\') {
                // Handle escape sequences
                $current .= $char;
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
                $current .= $char;
            } elseif (!$inQuotes && ($char === '<' || $char === '(')) {
                $depth += 1;
                $current .= $char;
            } elseif (!$inQuotes && ($char === '>' || $char === ')')) {
                $depth -= 1;
                $current .= $char;
            } elseif (!$inQuotes && $char === ',' && $depth === 0) {
                $trimmed = self::trim($current);
                if ($trimmed !== '') {
                    $addresses[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $char;
            }
            $prevChar = $char;
        }

        // Don't forget the last address
        $trimmed = self::trim($current);
        if ($trimmed !== '') {
            $addresses[] = $trimmed;
        }

        return array_map(self::parseEmailAddress(...), $addresses);
    }

    /** `String.prototype.trim()` (Unicode white space and line terminators). */
    private static function trim(string $s): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $s);
    }
}
