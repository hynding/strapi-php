<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp;

use Strapi\ContentManager\Utils\Utils as CmUtils;
use Strapi\Core\Strapi;

/** Port of server/src/mcp/register-content-manager-mcp-tools.ts. */
final class RegisterContentManagerMcpTools
{
    /**
     * Registers derived content-type MCP tools via strapi.ai.mcp.registerTool(). Must be called
     * before the MCP HTTP server starts (the content-manager bootstrap).
     */
    public static function registerContentManagerMcpTools(Strapi $strapi): void
    {
        // Performance only: registerTool() is safe when MCP is disabled (definitions are stored but
        // never exposed). Skip the expensive derivation below when the MCP server will not start.
        if ($strapi->ai()->mcp()->isEnabled() !== true) {
            return;
        }

        $localeCodes = null;
        $defaultLocale = null;
        if ($strapi->hasPlugin('i18n')) {
            $locales = $strapi->plugin('i18n')->service('locales');
            if (method_exists($locales, 'find') && method_exists($locales, 'getDefaultLocale')) {
                $found = $locales->find();
                $localeCodes = array_values(array_map(static fn (mixed $locale): string => is_array($locale) ? (string) ($locale['code'] ?? '') : '', is_array($found) ? $found : []));
                $default = $locales->getDefaultLocale();
                $defaultLocale = is_string($default) ? $default : null;
            }
        }

        $models = CmUtils::getService($strapi, 'content-types')->findDisplayedContentTypes();
        $tools = DeriveContentTypeMcpTools::deriveDisplayedContentTypeMcpToolDefinitions($strapi, $models, [
            'localeCodes' => $localeCodes,
            'defaultLocale' => $defaultLocale,
        ]);

        foreach ($tools as $tool) {
            $strapi->ai()->mcp()->registerTool($tool);
        }
    }
}
