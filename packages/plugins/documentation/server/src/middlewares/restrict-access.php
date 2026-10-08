<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Middlewares;

use Strapi\Core\Core;
use Strapi\Core\Middlewares\Session;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/middlewares/restrict-access.ts: the route middleware guarding the Swagger UI
 * when `restrictedAccess` is on (a `ctx.session.documentation.logged` session, set by the login
 * form). Upstream reads the global `strapi` (`Core::instance()`); koa-session's `ctx.session` is
 * the `strapi::session` state ({@see Session::STATE_KEY}).
 */
final class RestrictAccess
{
    public function __invoke(Context $ctx, callable $next): mixed
    {
        $strapi = Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');

        $pluginStore = $strapi->store()(['type' => 'plugin', 'name' => 'documentation']);

        $config = $pluginStore->get(['key' => 'config']);

        if (!is_array($config) || !($config['restrictedAccess'] ?? false)) {
            return $next();
        }

        $session = $ctx->state()->has(Session::STATE_KEY) ? $ctx->state()->get(Session::STATE_KEY) : null;
        $logged = is_array($session) ? ($session['documentation']['logged'] ?? null) : null;

        if (!$logged) {
            $querystring = method_exists($ctx, 'querystring') ? (string) $ctx->querystring() : '';
            $querystring = $querystring !== '' ? "?{$querystring}" : '';

            $ctx->redirect(self::toJsString($strapi->config()->get('server.url')) . "/documentation/login{$querystring}");

            return null;
        }

        // Execute the action.
        return $next();
    }

    private static function toJsString(mixed $value): string
    {
        return $value === null ? 'undefined' : (is_scalar($value) ? (string) $value : '');
    }
}
