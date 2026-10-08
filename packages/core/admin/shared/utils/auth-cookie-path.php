<?php

declare(strict_types=1);

namespace Strapi\Admin\Shared\Utils;

/**
 * Port of shared/utils/auth-cookie-path.ts.
 *
 * Path attribute of the admin auth (access) token cookie, configurable through
 * `admin.auth.cookie.path` so multiple Strapi instances on the same parent domain can keep
 * separate cookies (e.g. `/strapi-de/admin`). Defaults to `/admin` to match the refresh-cookie
 * path used by `getRefreshCookieOptions`.
 */
final class AuthCookiePath
{
    public const DEFAULT_AUTH_COOKIE_PATH = '/admin';

    private static function isValidCookiePath(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }

        // RFC 6265 path-av: any CHAR except CTLs or ";".
        return preg_match('/[\x00-\x1F\x7F;]/', $path) !== 1;
    }

    /** @param (callable(string): void)|null $warn */
    public static function resolveAuthCookiePath(?string $configuredPath = null, ?callable $warn = null): string
    {
        $cookiePath = trim($configuredPath ?? '');

        if ($cookiePath === '') {
            return self::DEFAULT_AUTH_COOKIE_PATH;
        }

        if (!self::isValidCookiePath($cookiePath)) {
            $message = sprintf(
                'Ignoring invalid admin auth cookie path "%s" (must be an absolute path without ";"); using "%s" instead.',
                $cookiePath,
                self::DEFAULT_AUTH_COOKIE_PATH
            );
            $warn !== null ? $warn($message) : error_log($message);

            return self::DEFAULT_AUTH_COOKIE_PATH;
        }

        return $cookiePath;
    }
}
