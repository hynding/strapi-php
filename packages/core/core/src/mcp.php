<?php

declare(strict_types=1);

namespace Strapi\Core;

use Strapi\Core\Services\Mcp\PromptRegistry;
use Strapi\Core\Services\Mcp\ResourceRegistry;
use Strapi\Core\Services\Mcp\ToolRegistry;

/**
 * Port of packages/core/core/src/mcp.ts: the public `ai.mcp` builders (`defineTool`,
 * `defineResource`, `definePrompt`), which return the definition unchanged.
 */
final class Mcp
{
    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    public static function defineTool(array $tool): array
    {
        return ToolRegistry::makeMcpToolDefinition($tool);
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public static function defineResource(array $resource): array
    {
        return ResourceRegistry::makeMcpResourceDefinition($resource);
    }

    /**
     * @param array<string, mixed> $prompt
     * @return array<string, mixed>
     */
    public static function definePrompt(array $prompt): array
    {
        return PromptRegistry::makeMcpPromptDefinition($prompt);
    }
}
