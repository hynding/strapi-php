<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp;

/** Port of server/src/mcp/utils.ts. */
final class Utils
{
    /**
     * Wraps a plain object into the dual-representation MCP tool return value (text + structuredContent).
     *
     * @param array<string, mixed> $structuredContent
     * @return array{content: list<array{type: string, text: string}>, structuredContent: array<string, mixed>}
     */
    public static function ok(array $structuredContent): array
    {
        return [
            'content' => [['type' => 'text', 'text' => (string) json_encode($structuredContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]],
            'structuredContent' => $structuredContent,
        ];
    }
}
