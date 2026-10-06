<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\Mcp\Mcp as McpService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/mcp.ts (the MCP service is a stub). */
final class Mcp extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('ai.mcp', static fn (): McpService => McpService::createMcpService($strapi));
    }

    public function bootstrap(Strapi $strapi): void
    {
        $mcp = $strapi->get('ai.mcp');
        if ($mcp->isEnabled()) {
            $strapi->log()->debug('[MCP] MCP server is not available in the PHP port yet');
        } else {
            $strapi->log()->debug('[MCP] MCP server is disabled in configuration');
        }
    }
}
