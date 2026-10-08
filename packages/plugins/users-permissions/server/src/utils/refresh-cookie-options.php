<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils;

/** Port of server/src/utils/refresh-cookie-options.js. */
final class RefreshCookieOptions
{
    /**
     * @param array<string, mixed> $upSessions
     * @return array{httpOnly: true, secure: bool, sameSite: mixed, path: mixed, domain: mixed, maxAge: mixed, overwrite: true}
     */
    public static function buildRefreshCookieOptions(array $upSessions, bool $isProduction): array
    {
        $cookie = is_array($upSessions['cookie'] ?? null) ? $upSessions['cookie'] : [];
        $isSecure = is_bool($cookie['secure'] ?? null) ? $cookie['secure'] : $isProduction;

        return [
            'httpOnly' => true,
            'secure' => $isSecure,
            'sameSite' => $cookie['sameSite'] ?? 'lax',
            'path' => $cookie['path'] ?? '/',
            'domain' => $cookie['domain'] ?? null,
            'maxAge' => $cookie['maxAge'] ?? null,
            'overwrite' => true,
        ];
    }
}
