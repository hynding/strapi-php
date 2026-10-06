<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/middlewares/ip.ts (koa-ip): `whitelist`/`blacklist` of IPs
 * (exact, CIDR `10.0.0.0/8`, or wildcard `192.168.*.*`). A request from a blocked address gets 403.
 */
final class Ip
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $whitelist = self::toList($config['whitelist'] ?? $config['allow'] ?? null);
        $blacklist = self::toList($config['blacklist'] ?? $config['deny'] ?? null);

        return static function (Context $ctx, callable $next) use ($whitelist, $blacklist): void {
            $ip = $ctx->ip();

            $pass = true;
            if ($whitelist !== []) {
                $pass = self::matchesAny($ip, $whitelist);
            }
            if ($pass && $blacklist !== [] && self::matchesAny($ip, $blacklist)) {
                $pass = false;
            }

            if (!$pass) {
                $ctx->setStatus(403);
                $ctx->setBody('Forbidden');

                return;
            }

            $next();
        };
    }

    /** @return list<string> */
    private static function toList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_map('strval', is_array($value) ? $value : [$value]));
    }

    /** @param list<string> $patterns */
    public static function matchesAny(string $ip, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($ip, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }
        if (str_contains($pattern, '/')) {
            [$subnet, $bits] = explode('/', $pattern, 2);
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $mask = -1 << (32 - (int) $bits);

            return ($ipLong & $mask) === ($subnetLong & $mask);
        }
        if (str_contains($pattern, '*')) {
            return fnmatch($pattern, $ip);
        }

        return false;
    }
}
