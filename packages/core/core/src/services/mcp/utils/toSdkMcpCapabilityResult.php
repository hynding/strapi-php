<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Utils;

/**
 * Port of services/mcp/utils/toSdkMcpCapabilityResult.ts: maps Strapi-owned capability results onto
 * the protocol shapes the SDK transports. Every content variant is translated explicitly (only the
 * fields of the variant are kept; absent ones stay absent, as `undefined` does in JSON).
 */
final class ToSdkMcpCapabilityResult
{
    private const CONTENT_FIELDS = [
        'text' => ['type', 'text', 'annotations', '_meta'],
        'image' => ['type', 'data', 'mimeType', 'annotations', '_meta'],
        'audio' => ['type', 'data', 'mimeType', 'annotations', '_meta'],
        'resource_link' => ['type', 'name', 'uri', 'title', 'description', 'mimeType', 'size', 'icons', 'annotations', '_meta'],
        'resource' => ['type', 'resource', 'annotations', '_meta'],
    ];

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public static function toSdkContentBlock(array $content): array
    {
        $type = $content['type'] ?? null;
        if (!is_string($type) || !isset(self::CONTENT_FIELDS[$type])) {
            throw new \InvalidArgumentException('[MCP] Unsupported content block: ' . json_encode($content));
        }
        $out = self::pick($content, self::CONTENT_FIELDS[$type]);
        if ($type === 'resource' && is_array($out['resource'] ?? null)) {
            $out['resource'] = self::toSdkResourceContents($out['resource']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function toSdkToolResult(array $result): array
    {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];

        return [
            'content' => array_map(self::toSdkContentBlock(...), array_values($content)),
            ...self::pick($result, ['structuredContent', 'isError']),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function toSdkPromptResult(array $result): array
    {
        $messages = is_array($result['messages'] ?? null) ? $result['messages'] : [];
        $extensionFields = array_diff_key($result, ['description' => true, 'messages' => true, '_meta' => true]);

        return [
            ...$extensionFields,
            ...self::pick($result, ['description']),
            'messages' => array_map(static fn (array $message): array => [
                'role' => $message['role'],
                'content' => self::toSdkContentBlock($message['content']),
            ], array_values($messages)),
            ...self::pick($result, ['_meta']),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function toSdkResourceReadResult(array $result): array
    {
        $contents = is_array($result['contents'] ?? null) ? $result['contents'] : [];
        $extensionFields = array_diff_key($result, ['contents' => true, '_meta' => true]);

        return [
            ...$extensionFields,
            'contents' => array_map(self::toSdkResourceContents(...), array_values($contents)),
            ...self::pick($result, ['_meta']),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function toSdkResourceListingMetadata(array $metadata): array
    {
        return self::pick($metadata, ['title', 'description', 'mimeType', 'size', 'icons', 'annotations', '_meta']);
    }

    /**
     * @param array<string, mixed> $contents
     * @return array<string, mixed>
     */
    private static function toSdkResourceContents(array $contents): array
    {
        return array_key_exists('text', $contents)
            ? self::pick($contents, ['uri', 'mimeType', 'text', '_meta'])
            : self::pick($contents, ['uri', 'mimeType', 'blob', '_meta']);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function pick(array $value, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && $value[$key] !== null) {
                $out[$key] = $value[$key];
            }
        }

        return $out;
    }
}
