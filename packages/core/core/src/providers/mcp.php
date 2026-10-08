<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\Mcp\Mcp as McpService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/mcp.ts. */
final class Mcp extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('ai.mcp', static fn (): McpService => McpService::createMcpService($strapi));
    }

    public function bootstrap(Strapi $strapi): void
    {
        $mcp = $strapi->ai()->mcp();
        if ($mcp->isEnabled()) {
            try {
                $strapi->log()->info('[MCP] Starting MCP server...');
                $mcp->start();
            } catch (\Throwable $error) {
                $strapi->log()->error('[MCP] Failed to start MCP server', ['error' => $error]);
            }
        } else {
            $strapi->log()->debug('[MCP] MCP server is disabled in configuration');
        }
    }

    public function destroy(Strapi $strapi): void
    {
        $mcp = $strapi->ai()->mcp();
        if ($mcp->isRunning()) {
            try {
                $strapi->log()->info('[MCP] Stopping MCP server...');
                $mcp->stop();
            } catch (\Throwable $error) {
                $strapi->log()->error('[MCP] Failed to stop MCP server', ['error' => $error]);
            }
        }
    }
}
