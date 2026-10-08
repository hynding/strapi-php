<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Handlers;

use Strapi\Core\Services\Mcp\Authentication;
use Strapi\Core\Services\Mcp\Internal\McpConfiguration;
use Strapi\Core\Services\Mcp\Metrics\Metrics;
use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Core\Services\Mcp\Sdk\StreamableHttpTransport;
use Strapi\Core\Services\Mcp\Utils\SendJsonRpcError;
use Strapi\Core\Services\Mcp\Utils\WithTimeout;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of services/mcp/handlers/handlePost.ts: `POST /mcp`. Authenticates the admin token, builds
 * an ephemeral McpServer for the token's ability, connects a stateless transport and lets it answer
 * the JSON-RPC message(s). Upstream opts out of Koa's response (`ctx.respond = false`) because the
 * SDK writes to `res`; here the transport writes the Context's response.
 *
 * The dependencies are upstream's `McpHandlerDependencies` (handlers/types.ts):
 * `{ strapi, authenticationStrategy, config, createServerWithRegistries, capabilityDefinitions }`.
 */
final class HandlePost
{
    /**
     * @param array{
     *     strapi: Strapi,
     *     authenticationStrategy: Authentication,
     *     config: McpConfiguration,
     *     createServerWithRegistries: callable,
     *     capabilityDefinitions: array{tools: \Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry, prompts: \Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry, resources: \Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry},
     *     createTransport?: callable(): StreamableHttpTransport,
     * } $deps
     * @return \Closure(Context): void
     */
    public static function createPostHandler(array $deps): \Closure
    {
        ['strapi' => $strapi, 'authenticationStrategy' => $authenticationStrategy, 'config' => $config] = $deps;
        $createServerWithRegistries = $deps['createServerWithRegistries'];
        $capabilityDefinitions = $deps['capabilityDefinitions'];
        $createTransport = $deps['createTransport'] ?? static fn (): StreamableHttpTransport => new StreamableHttpTransport();

        return static function (Context $ctx) use ($strapi, $authenticationStrategy, $config, $createServerWithRegistries, $capabilityDefinitions, $createTransport): void {
            $hadAuthenticatedMcpRequest = false;

            try {
                $authResult = $authenticationStrategy->authenticate($ctx);
                if ($authResult['authenticated'] === false) {
                    Metrics::sendDidNotAuthenticateMcpRequest($strapi, $authResult['reason']);
                    SendJsonRpcError::sendJsonRpcError($ctx, 'AUTHENTICATION_REQUIRED');

                    return;
                }

                $hadAuthenticatedMcpRequest = true;
                Metrics::sendDidUseMcpServer($strapi);

                // Let audit logs pick up MCP actions and tag their origin.
                $ctx->state()->set('user', $authResult['user']);
                $ctx->state()->set('auditSource', 'mcp');

                ['mcpServer' => $mcpServer] = $createServerWithRegistries([
                    'strapi' => $strapi,
                    'definitions' => $capabilityDefinitions,
                    'isDevMode' => $config->isDevMode(),
                    'ability' => $authResult['ability'],
                    'user' => $authResult['user'],
                ]);

                if (!$mcpServer instanceof McpServer) {
                    throw new \UnexpectedValueException('createServerWithRegistries must return an McpServer');
                }

                $transport = $createTransport();

                try {
                    WithTimeout::withTimeout(static fn () => $mcpServer->connect($transport), $config->connectTimeoutMs, 'mcpServer.connect');

                    $requestBody = $ctx->requestBody();
                    WithTimeout::withTimeout(static fn () => $transport->handleRequest($ctx, $requestBody), $config->requestTimeoutMs, 'transport.handleRequest');
                } finally {
                    $mcpServer->close();
                }
            } catch (\Throwable $error) {
                $strapi->log()->error('[MCP] Error handling POST request', [
                    'error' => $error->getMessage(),
                    'stack' => $error->getTraceAsString(),
                ]);

                SendJsonRpcError::sendJsonRpcError($ctx, 'INTERNAL_ERROR');

                if ($hadAuthenticatedMcpRequest) {
                    Metrics::sendDidNotHandleMcpRequest($strapi, Metrics::classifyMcpRequestFailure($error));
                }
            }
        };
    }
}
