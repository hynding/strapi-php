<?php

declare(strict_types=1);

namespace Strapi\Admin\Shared\Utils;

/**
 * Port of shared/utils/auth-cookie-name.ts.
 *
 * Name of the cookie holding the admin auth (access) token, configurable through
 * `admin.auth.cookie.name` so it cannot collide with a same-named cookie set by another
 * application on a shared parent domain.
 */
final class AuthCookieName
{
    public const DEFAULT_AUTH_COOKIE_NAME = 'jwtToken';

    // RFC 6265 cookie-name token characters.
    private const VALID_COOKIE_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /** @param (callable(string): void)|null $warn defaults to `error_log()` (upstream: console.warn) */
    public static function resolveAuthCookieName(?string $configuredName = null, ?callable $warn = null): string
    {
        $cookieName = trim($configuredName ?? '');

        if ($cookieName === '') {
            return self::DEFAULT_AUTH_COOKIE_NAME;
        }

        if (preg_match(self::VALID_COOKIE_NAME, $cookieName) !== 1) {
            self::warn($warn, sprintf(
                'Ignoring invalid admin auth cookie name "%s" (must only contain RFC 6265 cookie-name characters); using "%s" instead.',
                $cookieName,
                self::DEFAULT_AUTH_COOKIE_NAME
            ));

            return self::DEFAULT_AUTH_COOKIE_NAME;
        }

        return $cookieName;
    }

    private static function warn(?callable $warn, string $message): void
    {
        if ($warn !== null) {
            $warn($message);

            return;
        }

        error_log($message);
    }
}
