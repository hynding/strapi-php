<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp;

use Strapi\ContentManager\Controllers\Utils\Metadata;
use Strapi\ContentManager\Mcp\Sanitizers\ShapeRelations;
use Strapi\Core\Strapi;

/** Port of server/src/mcp/utils.ts. */
final class Utils
{
    /**
     * A content-type uid as a safe MCP tool-name segment: `api::article.article` → `article`,
     * `api::writer.editor` → `writer_editor`, `plugin::i18n.locale` → `plugin-i18n_locale`.
     */
    public static function slugifyUidForMcpToolName(string $uid): string
    {
        [$namespace, $modelName] = array_pad(explode('::', $uid, 2), 2, '');
        $parts = array_map('strtolower', explode('.', $modelName));

        if ($namespace === 'api') {
            return ($parts[0] ?? '') === ($parts[1] ?? null) ? $parts[0] : implode('_', $parts);
        }

        return strtolower($namespace) . '-' . implode('_', $parts);
    }

    /**
     * Output chokepoint for MCP handlers returning `{ data, meta }`. Order matters — calculate,
     * then strip: permission-based sanitization, then formatDocumentWithMetadata (computes
     * `data.status` and `localizations[].status`), then relation shaping on the formatted data.
     *
     * @param \Strapi\ContentManager\Services\PermissionChecker $permissionChecker
     * @param array{availableLocales?: bool, availableStatus?: bool} $opts
     * @return array<string, mixed>
     */
    public static function sanitizeFormatShape(Strapi $strapi, object $permissionChecker, string $uid, mixed $doc, array $opts = []): array
    {
        $sanitized = $permissionChecker->sanitizeOutput($doc);
        $formatted = Metadata::formatDocumentWithMetadata($strapi, $permissionChecker, $uid, is_array($sanitized) ? $sanitized : null, $opts);

        if (!is_array($formatted['data'] ?? null)) {
            return $formatted;
        }

        return [...$formatted, 'data' => ShapeRelations::shapeRelationsForMcp($strapi, $uid, $formatted['data'])];
    }

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

    /**
     * The `title` and `description` of a derived tool, with the operation-specific notes.
     *
     * @return array{title: string, description: string}
     */
    public static function describeTool(string $apiID, string $uid, string $operation): array
    {
        $operationNoteByType = [
            'write' => ' Creates or updates the single-type document. If no document exists, creates one; otherwise updates the existing draft.',
            'publish' => ' Operates on an existing document by documentId and may return a different numeric id for the published version row.',
            'unpublish' => ' Operates on an existing document by documentId and may return a different numeric id for the draft version row.',
            'discard_draft' => ' Operates on an existing document by documentId; treat documentId as the stable identity.',
        ];

        return [
            'title' => "Content: {$apiID} — {$operation}",
            'description' => "Content-manager {$operation} for {$uid}." . ($operationNoteByType[$operation] ?? ''),
        ];
    }
}
