<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Middleware;

use Strapi\Core\Services\Server\Context;

/**
 * Port of services/mcp/middleware/oauthDiscoveryFallback.ts.
 *
 * OAuth discovery paths probed by MCP SDK clients during the authentication fallback flow
 * (RFC 8414 § 3, RFC 7591, OpenID Connect Discovery). Strapi is a resource server, not an
 * authorization server, so these paths have no real handler; without this middleware the
 * plain-text 404/405 responses crash clients expecting JSON (e.g. Claude Code).
 *
 * The middleware only fires when downstream already returned 404 or 405, so user-defined routes on
 * these paths are never shadowed.
 */
final class OauthDiscoveryFallback
{
    private const OAUTH_DISCOVERY_PROBES = [
        ['method' => 'GET', 'path' => '/.well-known/oauth-authorization-server'],
        ['method' => 'GET', 'path' => '/.well-known/openid-configuration'],
        ['method' => 'POST', 'path' => '/register'],
    ];

    /** @return \Closure(Context, callable): void */
    public static function createOAuthDiscoveryFallbackMiddleware(): \Closure
    {
        return static function (Context $ctx, callable $next): void {
            $next();

            if ($ctx->status() !== 404 && $ctx->status() !== 405) {
                return;
            }

            $isOAuthProbe = false;
            foreach (self::OAUTH_DISCOVERY_PROBES as $probe) {
                $isOAuthProbe = $isOAuthProbe || ($ctx->method() === $probe['method'] && $ctx->path() === $probe['path']);
            }

            if ($isOAuthProbe === false) {
                return;
            }

            $ctx->setStatus(404);
            $ctx->set('Content-Type', 'application/json');
            $ctx->setBody(['error' => 'not_found', 'error_description' => 'OAuth is not supported']);
        };
    }
}
