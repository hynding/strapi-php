<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail;

/**
 * Port of src/addressing.ts.
 *
 * Address parsing and grouping — aligned with the legacy `sendmail` npm package
 * (guileen/node-sendmail) behavior for recipient grouping and domain extraction.
 */
final class Addressing
{
    /** Legacy sendmail host extraction regex from guileen/node-sendmail@1.6.1. */
    private const string LEGACY_HOST_REGEX = '/[^@]+@([\w\d\-.]+)/';

    /**
     * Do not run the legacy regex on unbounded input (defense in depth; the pattern is not ReDoS-prone,
     * but capping work is cheap and matches RFC 5321 practical address size expectations).
     */
    private const int MAX_INPUT_LEN_FOR_LEGACY_HOST_REGEX = 320;

    /** Extract bare email from `Name <email@domain>` or return trimmed input (linear time, no regex). */
    public static function extractEmail(string $address): string
    {
        $trimmed = trim($address);
        $open = strpos($trimmed, '<');
        if ($open === false) {
            return $trimmed;
        }
        $close = strpos($trimmed, '>', $open + 1);
        if ($close === false) {
            return $trimmed;
        }

        return trim(substr($trimmed, $open + 1, $close - $open - 1));
    }

    /**
     * Split address lists (legacy package accepted both string and array).
     *
     * @param string|list<mixed>|null $addresses
     * @return list<string>
     */
    public static function parseAddressList(string|array|null $addresses): array
    {
        if ($addresses === null || $addresses === '' || $addresses === []) {
            return [];
        }
        $items = is_array($addresses)
            ? array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $addresses)
            : explode(',', $addresses);

        return array_values(array_filter(
            array_map(self::extractEmail(...), $items),
            static fn (string $a): bool => $a !== '',
        ));
    }

    /** Domain part of an email address (after the last `@`). */
    public static function getHostFromAddress(string $email): ?string
    {
        $normalized = self::extractEmail($email);
        if (mb_strlen($normalized, 'UTF-8') > self::MAX_INPUT_LEN_FOR_LEGACY_HOST_REGEX) {
            return null;
        }
        if (preg_match(self::LEGACY_HOST_REGEX, $normalized, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Group recipient addresses by recipient domain (MX routing key).
     *
     * @param list<string> $recipients
     * @return array<string, list<string>>
     */
    public static function groupRecipientsByDomain(array $recipients): array
    {
        $groups = [];
        foreach ($recipients as $raw) {
            // String(undefined) — legacy object-key coercion
            $host = self::getHostFromAddress($raw) ?? 'undefined';
            $groups[$host] ??= [];
            $groups[$host][] = $raw;
        }

        return $groups;
    }

    /**
     * Collect all recipients from to / cc / bcc (same sources as legacy sendmail).
     *
     * @param array{to?: string|list<mixed>|null, cc?: string|list<mixed>|null, bcc?: string|list<mixed>|null} $mail
     * @return list<string>
     */
    public static function collectRecipients(array $mail): array
    {
        return [
            ...self::parseAddressList($mail['to'] ?? null),
            ...self::parseAddressList($mail['cc'] ?? null),
            ...self::parseAddressList($mail['bcc'] ?? null),
        ];
    }
}
