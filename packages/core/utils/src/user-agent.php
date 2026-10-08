<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/user-agent.ts.
 *
 * Minimal, dependency-free User-Agent parsing. Intentionally heuristic: it only aims to produce
 * a human-readable label (e.g. "Chrome on macOS") for displaying active sessions/devices. It is
 * not a full UA parser and should not be relied upon for feature detection.
 *
 * Upstream returns `{ browser?, os?, deviceName? }` with `undefined` members; here a member that
 * would be `undefined` is simply absent from the returned array (same JSON).
 *
 * @phpstan-type ParsedUserAgent array{browser?: string, os?: string, deviceName?: string}
 */
final class UserAgent
{
    /**
     * Upper bound on the User-Agent length we are willing to scan. Real-world UA strings are
     * comfortably under this, and browser/OS tokens always appear early, so clamping protects
     * against pathologically large attacker-controlled headers without losing accuracy.
     */
    public const int MAX_UA_LENGTH = 1024;

    private static function detectBrowser(string $ua): ?string
    {
        // Order matters: more specific tokens must be checked first.
        if (preg_match('~\bEdg(?:e|A|iOS)?/~', $ua) === 1) {
            return 'Edge';
        }
        if (preg_match('~\b(?:OPR|Opera)/~', $ua) === 1) {
            return 'Opera';
        }
        if (preg_match('~\bSamsungBrowser/~', $ua) === 1) {
            return 'Samsung Internet';
        }
        // Chromium forks below ship a "Chrome/" token too, so they must be matched before the
        // generic Chrome check or they'd all collapse into "Chrome".
        if (preg_match('~\bVivaldi/~', $ua) === 1) {
            return 'Vivaldi';
        }
        if (preg_match('~\b(?:YaBrowser|Yowser)/~', $ua) === 1) {
            return 'Yandex Browser';
        }
        if (preg_match('~\bBrave/~', $ua) === 1) {
            return 'Brave';
        }
        if (preg_match('~\b(?:Firefox|FxiOS)/~', $ua) === 1) {
            return 'Firefox';
        }
        if (preg_match('~\b(?:Chrome|CriOS|Chromium)/~', $ua) === 1) {
            return 'Chrome';
        }
        // Safari ships "Safari/xxx" but so do Chrome/Edge; only treat as Safari when no Chrome token.
        if (preg_match('~\bVersion/[\d.]+ (?:Mobile/\S+ )?Safari/~', $ua) === 1 || preg_match('~\bSafari/~', $ua) === 1) {
            return 'Safari';
        }

        return null;
    }

    private static function detectOS(string $ua): ?string
    {
        if (preg_match('~\bWindows NT\b~', $ua) === 1) {
            return 'Windows';
        }
        if (preg_match('~\b(?:iPhone|iPad|iPod)\b~', $ua) === 1) {
            return 'iOS';
        }
        if (preg_match('~\bAndroid\b~', $ua) === 1) {
            return 'Android';
        }
        // "Mac OS X" appears on iOS too, so this must come after the iOS check.
        if (preg_match('~\b(?:Macintosh|Mac OS X)\b~', $ua) === 1) {
            return 'macOS';
        }
        if (preg_match('~\bCrOS\b~', $ua) === 1) {
            return 'ChromeOS';
        }
        if (preg_match('~\bLinux\b~', $ua) === 1) {
            return 'Linux';
        }

        return null;
    }

    /**
     * Parses a User-Agent string into a best-effort browser/OS and a display label.
     * Returns an empty array when the input is missing, not a string, or unrecognized.
     *
     * @return ParsedUserAgent
     */
    public static function parseUserAgent(mixed $userAgent = null): array
    {
        if (!is_string($userAgent) || $userAgent === '') {
            return [];
        }

        // Clamp before scanning so an oversized header cannot drive the regex work.
        $ua = strlen($userAgent) > self::MAX_UA_LENGTH ? substr($userAgent, 0, self::MAX_UA_LENGTH) : $userAgent;

        $browser = self::detectBrowser($ua);
        $os = self::detectOS($ua);

        $result = [];
        if ($browser !== null) {
            $result['browser'] = $browser;
        }
        if ($os !== null) {
            $result['os'] = $os;
        }
        if ($browser !== null && $os !== null) {
            $result['deviceName'] = "{$browser} on {$os}";
        } elseif ($browser !== null) {
            $result['deviceName'] = $browser;
        } elseif ($os !== null) {
            $result['deviceName'] = $os;
        }

        return $result;
    }

    /** Convenience helper returning only the human-readable device label, or null. */
    public static function getDeviceName(mixed $userAgent = null): ?string
    {
        return self::parseUserAgent($userAgent)['deviceName'] ?? null;
    }
}
