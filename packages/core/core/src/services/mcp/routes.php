<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp;

use Strapi\Core\Services\Mcp\Internal\McpConfiguration;
use Strapi\Core\Services\Mcp\Utils\SendJsonRpcError;
use Strapi\Core\Services\Server\Context;

/** Port of services/mcp/routes.ts. */
final class Routes
{
    /**
     * Handler for unsupported HTTP methods on the /mcp endpoint: a JSON-RPC error instead of plain
     * text so MCP clients can parse it.
     */
    public static function handleMethodNotAllowed(Context $ctx): void
    {
        $ctx->set('Allow', 'POST');
        SendJsonRpcError::sendJsonRpcError($ctx, 'METHOD_NOT_ALLOWED');
    }

    /**
     * Creates MCP route definitions for registration with Strapi server.
     *
     * @internal
     * @param array{handlePost: callable} $handlers
     * @return list<array{method: string, path: string, handler: callable, config: array{auth: false}}>
     */
    public static function createMcpRoutes(McpConfiguration $config, array $handlers): array
    {
        $noAuth = ['auth' => false];
        $notAllowed = self::handleMethodNotAllowed(...);

        return [
            ['method' => 'POST', 'path' => $config->path, 'handler' => $handlers['handlePost'], 'config' => $noAuth],
            ['method' => 'GET', 'path' => $config->path, 'handler' => $notAllowed, 'config' => $noAuth],
            ['method' => 'DELETE', 'path' => $config->path, 'handler' => $notAllowed, 'config' => $noAuth],
            ['method' => 'PUT', 'path' => $config->path, 'handler' => $notAllowed, 'config' => $noAuth],
            ['method' => 'PATCH', 'path' => $config->path, 'handler' => $notAllowed, 'config' => $noAuth],
        ];
    }
}
