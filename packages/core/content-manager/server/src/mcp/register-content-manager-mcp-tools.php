<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp;

use Strapi\Core\Strapi;

/**
 * Port of server/src/mcp/register-content-manager-mcp-tools.ts.
 *
 * Core's MCP service is a stub without `registerTool()` and the tool derivation
 * (`derive-content-type-mcp-tools`, handlers, schemas) is not ported yet: when MCP is enabled a
 * warning is logged and no tool is registered.
 */
final class RegisterContentManagerMcpTools
{
    /**
     * Registers derived content-type MCP tools via strapi.ai.mcp.registerTool().
     * Must be called from the plugin register phase, before the MCP HTTP server starts.
     */
    public static function registerContentManagerMcpTools(Strapi $strapi): void
    {
        // Performance only: registerTool() is safe when MCP is disabled (definitions are stored but
        // never exposed). Skip the expensive derivation below when the MCP server will not start.
        if ($strapi->ai()->mcp()->isEnabled() !== true) {
            return;
        }

        // PLACEHOLDER: deriveDisplayedContentTypeMcpToolDefinitions + strapi.ai.mcp.registerTool
        $strapi->log()->warning('[content-manager] MCP content-type tools are not ported yet; no tool registered.');
    }
}
