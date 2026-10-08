<?php

declare(strict_types=1);

namespace Strapi\Admin\Shared\Utils;

/**
 * Port of shared/utils/auth-cookie-domain.ts.
 *
 * Domain attribute of the admin auth (access) token cookie, configurable through
 * `admin.auth.cookie.domain` (falling back to `admin.auth.domain`). Defaults to `null`
 * (upstream: `undefined`, a host-only cookie).
 */
final class AuthCookieDomain
{
    public const DEFAULT_AUTH_COOKIE_DOMAIN = null;

    private static function isValidCookieDomain(string $domain): bool
    {
        // RFC 6265 domain-av: a host name. No leading dot required, no scheme, no
        // path, no port, and none of the control/attribute-separator characters.
        return preg_match('/[\x00-\x1F\x7F;,\s\/:]/', $domain) !== 1;
    }

    /** @param (callable(string): void)|null $warn */
    public static function resolveAuthCookieDomain(?string $configuredDomain = null, ?callable $warn = null): ?string
    {
        $cookieDomain = trim($configuredDomain ?? '');

        if ($cookieDomain === '') {
            return self::DEFAULT_AUTH_COOKIE_DOMAIN;
        }

        if (!self::isValidCookieDomain($cookieDomain)) {
            $message = sprintf(
                'Ignoring invalid admin auth cookie domain "%s" (must be a bare host name); using a host-only cookie instead.',
                $cookieDomain
            );
            $warn !== null ? $warn($message) : error_log($message);

            return self::DEFAULT_AUTH_COOKIE_DOMAIN;
        }

        return $cookieDomain;
    }
}
