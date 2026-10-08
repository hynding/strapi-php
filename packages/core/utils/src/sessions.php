<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/sessions.ts.
 *
 * Members upstream leaves `undefined` are absent from the returned arrays (same JSON).
 * Dates are emitted like `new Date(x).toISOString()`: UTC, millisecond precision,
 * `Y-m-d\TH:i:s.v\Z`.
 *
 * @phpstan-type SessionEntryLike array{
 *     sessionId: string,
 *     deviceId?: string|null,
 *     createdAt?: \DateTimeInterface|string|int|float|null,
 *     metadata?: array<string, mixed>|null
 * }
 * @phpstan-type SanitizedSessionEntry array{
 *     id: string,
 *     deviceId?: string,
 *     deviceName?: string,
 *     current: bool,
 *     loginAt?: string,
 *     lastActiveAt?: string
 * }
 */
final class Sessions
{
    public const string ISO_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /**
     * Sanitizes a session record for client consumption (active devices UI/API).
     * Never includes tokens or internal database identifiers.
     *
     * @param SessionEntryLike $session
     * @return SanitizedSessionEntry
     */
    public static function sanitizeSessionEntry(array $session, ?string $currentSessionId = null): array
    {
        $metadata = $session['metadata'] ?? [];

        $result = ['id' => $session['sessionId']];
        if (isset($session['deviceId'])) {
            $result['deviceId'] = $session['deviceId'];
        }
        if (isset($metadata['deviceName']) && is_string($metadata['deviceName'])) {
            $result['deviceName'] = $metadata['deviceName'];
        }
        $result['current'] = $currentSessionId !== null && $currentSessionId !== '' && $session['sessionId'] === $currentSessionId;
        if (isset($metadata['loginAt']) && is_string($metadata['loginAt'])) {
            $result['loginAt'] = $metadata['loginAt'];
        }
        // The active record is re-created on each rotation, so its creation time is the best
        // available "last used" signal without an extra write per request.
        $createdAt = $session['createdAt'] ?? null;
        if ($createdAt !== null && $createdAt !== '' && $createdAt !== 0 && $createdAt !== 0.0) {
            $result['lastActiveAt'] = self::toISOString($createdAt);
        }

        return $result;
    }

    /**
     * Builds origin-defined session metadata from generic request context fields.
     *
     * @param array{userAgent?: string|null, loginAt?: string|null} $params
     * @return array<string, mixed>
     */
    public static function buildSessionMetadata(array $params): array
    {
        $deviceName = UserAgent::getDeviceName($params['userAgent'] ?? null);

        $metadata = ['loginAt' => $params['loginAt'] ?? self::toISOString(new \DateTimeImmutable())];
        if ($deviceName !== null && $deviceName !== '') {
            $metadata['deviceName'] = $deviceName;
        }

        return $metadata;
    }

    /**
     * Sorts sessions for display: current session first, then most recently used.
     * Returns a new list; the input is not modified.
     *
     * @template T of array{current: bool, lastActiveAt?: string|null}
     * @param array<T> $sessions
     * @return list<T>
     */
    public static function sortSessionsForDisplay(array $sessions): array
    {
        $sorted = array_values($sessions);
        usort($sorted, static function (array $a, array $b): int {
            if ($a['current'] !== $b['current']) {
                return $a['current'] ? -1 : 1;
            }

            return strcmp($b['lastActiveAt'] ?? '', $a['lastActiveAt'] ?? '');
        });

        return $sorted;
    }

    /**
     * `new Date(value).toISOString()`: a number is milliseconds since the epoch, a string is
     * parsed as a date. Throws \RangeException('Invalid time value') like JS on an unparsable date.
     */
    public static function toISOString(\DateTimeInterface|string|int|float $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $date = \DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value)) {
            try {
                $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new \RangeException('Invalid time value');
            }
        } else {
            if (!is_finite((float) $value)) {
                throw new \RangeException('Invalid time value');
            }
            $ms = (int) floor((float) $value);
            $seconds = intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0);
            $millis = $ms - $seconds * 1000;
            $date = (new \DateTimeImmutable('@' . $seconds))->modify("+{$millis} milliseconds");
            if ($date === false) {
                throw new \RangeException('Invalid time value');
            }
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format(self::ISO_FORMAT);
    }
}
