<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Internal;

use Strapi\Core\Services\Mcp\PromptRegistry;
use Strapi\Core\Services\Mcp\ResourceRegistry;
use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Core\Services\Mcp\ToolRegistry;
use Strapi\Core\Strapi;

/**
 * Port of services/mcp/internal/McpServerFactory.ts: one McpServer per request, with the
 * capability registries bound and synced to the session's ability.
 *
 * @phpstan-type McpCapabilityDefinitions array{tools: McpCapabilityDefinitionRegistry, prompts: McpCapabilityDefinitionRegistry, resources: McpCapabilityDefinitionRegistry}
 */
final class McpServerFactory
{
    /**
     * @param array{strapi: Strapi, definitions: McpCapabilityDefinitions, isDevMode: bool, ability: \Strapi\Permissions\Engine\Abilities\Ability, user: mixed} $params
     * @return array{mcpServer: McpServer, registries: array{tools: ToolRegistry, prompts: PromptRegistry, resources: ResourceRegistry}}
     */
    public static function createMcpServerWithRegistries(array $params): array
    {
        ['strapi' => $strapi, 'definitions' => $definitions, 'isDevMode' => $isDevMode, 'ability' => $ability, 'user' => $user] = $params;

        $capabilities = ['logging' => []];
        // Advertise capability categories when definitions exist (server-level), not per-user enabled
        // count. Clients discover the real set via tools/list, prompts/list, resources/list after sync.
        if ($definitions['tools']->size() > 0) {
            $capabilities['tools'] = [];
        }
        if ($definitions['prompts']->size() > 0) {
            $capabilities['prompts'] = [];
        }
        if ($definitions['resources']->size() > 0) {
            $capabilities['resources'] = [];
        }

        $mcpServer = new McpServer(['name' => 'strapi-mcp-server', 'version' => '1.0.0'], ['capabilities' => $capabilities]);

        // Bootstrap registries with current definitions
        $tools = new ToolRegistry($strapi, $definitions['tools'], $ability, $user);
        $prompts = new PromptRegistry($strapi, $definitions['prompts']);
        $resources = new ResourceRegistry($strapi, $definitions['resources']);

        // Register capabilities (disabled by default)
        $tools->bind($mcpServer);
        $prompts->bind($mcpServer);
        $resources->bind($mcpServer);

        SyncMcpSessionCapabilities::syncMcpSessionCapabilities(
            ['tools' => $tools, 'prompts' => $prompts, 'resources' => $resources],
            $definitions,
            $ability,
            $isDevMode,
        );

        return ['mcpServer' => $mcpServer, 'registries' => ['tools' => $tools, 'prompts' => $prompts, 'resources' => $resources]];
    }
}
