<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp;

/**
 * Port of server/src/mcp/types.ts (type-only).
 *
 * @phpstan-type McpHandlerContext array{userAbility: mixed, user?: mixed}
 * @phpstan-type McpToolHandlerReturn array{content: list<array{type: string, text: string}>, structuredContent: array<string, mixed>}
 * @phpstan-type UploadMcpTool array{name: string, title: string, description: string, telemetry: array{source: string, name: string}, auth: array{policies: list<array{action: string}>}, resolveInputSchema?: \Closure(): \Strapi\Utils\Zod\ZodType, resolveOutputSchema: \Closure(): \Strapi\Utils\Zod\ZodType, createHandler: \Closure}
 */
final class Types
{
}
