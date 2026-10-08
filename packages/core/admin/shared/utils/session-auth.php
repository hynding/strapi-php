<?php

declare(strict_types=1);

namespace Strapi\Admin\Shared\Utils;

use Strapi\Core\Services\SessionManager;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Sessions;

/**
 * Port of shared/utils/session-auth.ts. Upstream reads the global `strapi`; here every helper
 * that needs the instance takes it as its first argument.
 *
 * Cookie options use the `cookies` module's names (`httpOnly`, `secure`, `overwrite`, `domain`,
 * `path`, `sameSite`, `maxAge` in ms, `expires`); members upstream leaves `undefined` are `null`.
 */
final class SessionAuth
{
    private const ADMIN_ORIGIN = 'admin';
    private const SESSION_CONTENT_TYPE = 'admin::session';

    public const REFRESH_COOKIE_NAME = 'strapi_admin_refresh';

    public const DEFAULT_MAX_REFRESH_TOKEN_LIFESPAN = 30 * 24 * 60 * 60;
    public const DEFAULT_IDLE_REFRESH_TOKEN_LIFESPAN = 14 * 24 * 60 * 60;
    public const DEFAULT_MAX_SESSION_LIFESPAN = 1 * 24 * 60 * 60;
    public const DEFAULT_IDLE_SESSION_LIFESPAN = 2 * 60 * 60;

    /**
     * The resolvers are browser-shared, so they default to console.warn; on the server, route
     * their warnings through the Strapi logger instead.
     *
     * @return \Closure(string): void
     */
    private static function warnViaStrapiLog(Strapi $strapi): \Closure
    {
        return static function (string $message) use ($strapi): void {
            $strapi->log()->warning($message);
        };
    }

    private static function configString(Strapi $strapi, string $path): ?string
    {
        $value = $strapi->config()->get($path);

        return is_string($value) ? $value : null;
    }

    public static function getAccessCookieName(Strapi $strapi): string
    {
        return AuthCookieName::resolveAuthCookieName(self::configString($strapi, 'admin.auth.cookie.name'), self::warnViaStrapiLog($strapi));
    }

    public static function getAccessCookiePath(Strapi $strapi): string
    {
        return AuthCookiePath::resolveAuthCookiePath(self::configString($strapi, 'admin.auth.cookie.path'), self::warnViaStrapiLog($strapi));
    }

    public static function getAccessCookieDomain(Strapi $strapi): ?string
    {
        $configured = self::configString($strapi, 'admin.auth.cookie.domain');
        if ($configured === null || $configured === '') {
            $configured = self::configString($strapi, 'admin.auth.domain');
        }

        return AuthCookieDomain::resolveAuthCookieDomain($configured, self::warnViaStrapiLog($strapi));
    }

    /** `process.env.NODE_ENV === 'production'` */
    public static function isProduction(): bool
    {
        return getenv('NODE_ENV') === 'production';
    }

    /** @return array{httpOnly: true, secure: bool, overwrite: true, domain: string|null, path: string, sameSite: mixed, maxAge: null} */
    public static function getRefreshCookieOptions(Strapi $strapi, ?bool $secureRequest = null): array
    {
        $configuredSecure = $strapi->config()->get('admin.auth.cookie.secure');
        $isProduction = self::isProduction();

        $domain = self::getAccessCookieDomain($strapi);
        $path = self::getAccessCookiePath($strapi);

        $sameSite = $strapi->config()->get('admin.auth.cookie.sameSite') ?? 'lax';

        if (is_bool($configuredSecure)) {
            $isSecure = $configuredSecure;
        } elseif ($secureRequest !== null) {
            $isSecure = $isProduction && $secureRequest;
        } else {
            $isSecure = $isProduction;
        }

        return [
            'httpOnly' => true,
            'secure' => $isSecure,
            'overwrite' => true,
            'domain' => $domain,
            'path' => $path,
            'sameSite' => $sameSite,
            'maxAge' => null,
        ];
    }

    /**
     * @param 'refresh'|'session' $type
     * @return array{idleSeconds: int|float, maxSeconds: int|float}
     */
    private static function getLifespansForType(Strapi $strapi, string $type): array
    {
        if ($type === 'refresh') {
            $idleSeconds = self::toNumber($strapi->config()->get('admin.auth.sessions.idleRefreshTokenLifespan', self::DEFAULT_IDLE_REFRESH_TOKEN_LIFESPAN));
            $maxSeconds = self::toNumber($strapi->config()->get('admin.auth.sessions.maxRefreshTokenLifespan', self::DEFAULT_MAX_REFRESH_TOKEN_LIFESPAN));

            return ['idleSeconds' => $idleSeconds, 'maxSeconds' => $maxSeconds];
        }

        $idleSeconds = self::toNumber($strapi->config()->get('admin.auth.sessions.idleSessionLifespan', self::DEFAULT_IDLE_SESSION_LIFESPAN));
        $maxSeconds = self::toNumber($strapi->config()->get('admin.auth.sessions.maxSessionLifespan', self::DEFAULT_MAX_SESSION_LIFESPAN));

        return ['idleSeconds' => $idleSeconds, 'maxSeconds' => $maxSeconds];
    }

    /** JS `Number(x)` for config values (NaN becomes 0 here). */
    private static function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        return 0;
    }

    /**
     * @param string $type 'refresh'|'session'
     * @return array<string, mixed>
     */
    public static function buildCookieOptionsWithExpiry(Strapi $strapi, string $type, ?string $absoluteExpiresAtISO = null, ?bool $secureRequest = null): array
    {
        $base = self::getRefreshCookieOptions($strapi, $secureRequest);
        if ($type === 'session') {
            return $base;
        }

        ['idleSeconds' => $idleSeconds] = self::getLifespansForType($strapi, 'refresh');
        $now = (int) floor(microtime(true) * 1000);
        $idleExpiry = $now + (int) ($idleSeconds * 1000);
        $absoluteExpiry = $absoluteExpiresAtISO !== null && $absoluteExpiresAtISO !== ''
            ? (int) ((new \DateTimeImmutable($absoluteExpiresAtISO))->format('Uv'))
            : $idleExpiry;
        $chosen = min($idleExpiry, $absoluteExpiry);

        return [
            ...$base,
            'expires' => (new \DateTimeImmutable())->setTimestamp(intdiv($chosen, 1000)),
            'maxAge' => max(0, $chosen - $now),
        ];
    }

    public static function getSessionManager(Strapi $strapi): ?SessionManager
    {
        return $strapi->has('sessionManager') ? $strapi->sessionManager() : null;
    }

    public static function generateDeviceId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /** @return array{deviceId: string, rememberMe: bool} */
    public static function extractDeviceParams(mixed $requestBody): array
    {
        $body = is_array($requestBody) ? $requestBody : [];
        $deviceId = $body['deviceId'] ?? null;
        $deviceId = is_string($deviceId) && $deviceId !== '' ? $deviceId : self::generateDeviceId();
        $rememberMe = self::truthy($body['rememberMe'] ?? null);

        return ['deviceId' => $deviceId, 'rememberMe' => $rememberMe];
    }

    /** JS `Boolean(x)`. */
    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || (is_float($value) && is_nan($value)));
    }

    /** @return array<string, mixed> */
    public static function buildSessionMetadataFromContext(Context $ctx): array
    {
        return Sessions::buildSessionMetadata(['userAgent' => $ctx->header('User-Agent')]);
    }

    /**
     * Resolves the device id to use when revoking sessions on logout.
     * SSO assigns deviceId server-side, so the client-provided value may not match
     * the active session row. Prefer the deviceId stored on the session backing
     * the current access token when available.
     */
    public static function resolveLogoutDeviceId(Strapi $strapi, string $userId, ?string $sessionId, ?string $clientDeviceId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            $strapi->log()->debug('resolveLogoutDeviceId: no sessionId; falling back to client deviceId');

            return $clientDeviceId;
        }

        $session = $strapi->db()->query(self::SESSION_CONTENT_TYPE)->findOne([
            'where' => ['sessionId' => $sessionId],
        ]);

        if (!is_array($session) || ($session['userId'] ?? null) !== $userId || ($session['origin'] ?? null) !== self::ADMIN_ORIGIN) {
            $strapi->log()->debug('resolveLogoutDeviceId: access-token session missing or not owned; falling back to client deviceId');

            return $clientDeviceId;
        }

        return is_string($session['deviceId'] ?? null) ? $session['deviceId'] : $clientDeviceId;
    }
}
