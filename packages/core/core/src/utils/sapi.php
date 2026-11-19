<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/**
 * PHP port: what the server API means for a Strapi process. Upstream is one long-lived Node process;
 * PHP's CLI and FrankenPHP worker mode are too, but PHP-FPM, CGI, mod_php, LiteSpeed and the
 * built-in server run each request in a fresh process state, statics included.
 */
final class Sapi
{
    /** The SAPIs where each request starts afresh. */
    public const array PER_REQUEST = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server'];

    /** The per-request SAPIs without a console: their stdout is /dev/null, or the response itself (CGI). */
    public const array NO_CONSOLE = ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed'];

    public static function isPerRequest(string $sapi = PHP_SAPI): bool
    {
        return in_array($sapi, self::PER_REQUEST, true);
    }

    public static function hasNoConsole(string $sapi = PHP_SAPI): bool
    {
        return in_array($sapi, self::NO_CONSOLE, true);
    }
}
