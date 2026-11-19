<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/**
 * PHP port: what the server API means for a Strapi process. Upstream is one long-lived Node process;
 * PHP's CLI and FrankenPHP worker mode are too, but PHP-FPM, CGI, mod_php, LiteSpeed, the
 * built-in server and FrankenPHP's classic mode (`php_server` without `worker`) run each request
 * in a fresh process state, statics included.
 */
final class Sapi
{
    /** The SAPIs where each request starts afresh. */
    public const array PER_REQUEST = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server'];

    /** The per-request SAPIs without a console: their stdout is /dev/null, or the response itself (CGI). */
    public const array NO_CONSOLE = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed'];

    /**
     * @param bool|null $frankenphpWorker for the `frankenphp` SAPI, whether it runs in worker mode
     *                                    (default: FrankenPHP's `FRANKENPHP_WORKER` variable)
     */
    public static function isPerRequest(string $sapi = PHP_SAPI, ?bool $frankenphpWorker = null): bool
    {
        if ($sapi === 'frankenphp') {
            return !($frankenphpWorker ?? self::isFrankenphpWorker());
        }

        return in_array($sapi, self::PER_REQUEST, true);
    }

    /** FrankenPHP sets `FRANKENPHP_WORKER=1` for worker scripts (worker.go). */
    public static function isFrankenphpWorker(): bool
    {
        return getenv('FRANKENPHP_WORKER') === '1';
    }

    public static function hasNoConsole(string $sapi = PHP_SAPI): bool
    {
        return in_array($sapi, self::NO_CONSOLE, true);
    }
}
