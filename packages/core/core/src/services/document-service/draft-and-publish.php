<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of services/document-service/draft-and-publish.ts: the params transforms for draft & publish.
 * Each transform is a pure function of the params array (upstream curries the content type).
 */
final class DraftAndPublish
{
    /**
     * DP enabled -> set status to draft. DP disabled -> used mostly for parsing relations, no default needed.
     *
     * @param Schema|array<string, mixed> $contentType
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function setStatusToDraft(Schema|array $contentType, array $params): array
    {
        if (!ContentTypes::hasDraftAndPublish($contentType)) {
            return $params;
        }

        return [...$params, 'status' => 'draft'];
    }

    /**
     * Adds a default status of `draft` to the params.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function defaultToDraft(array $params): array
    {
        // Default to draft if no status is provided or it's invalid
        if (empty($params['status']) || $params['status'] !== 'published') {
            return [...$params, 'status' => 'draft'];
        }

        return $params;
    }

    /**
     * DP disabled -> ignore status. DP enabled -> set status to draft if no status is provided or it's invalid.
     *
     * @param Schema|array<string, mixed> $contentType
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function defaultStatus(Schema|array $contentType, array $params): array
    {
        if (!ContentTypes::hasDraftAndPublish($contentType)) {
            return $params;
        }

        if (empty($params['status']) || $params['status'] !== 'published') {
            return self::defaultToDraft($params);
        }

        return $params;
    }

    /**
     * In mutating actions we don't want user to set the publishedAt attribute.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function filterDataPublishedAt(array $params): array
    {
        if (!empty($params['data']['publishedAt'])) {
            return [...$params, 'data' => [...$params['data'], 'publishedAt' => null]];
        }

        return $params;
    }

    /**
     * Add status lookup query to the params.
     *
     * @param Schema|array<string, mixed> $contentType
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function statusToLookup(Schema|array $contentType, array $params): array
    {
        if (!ContentTypes::hasDraftAndPublish($contentType)) {
            return $params;
        }

        $lookup = is_array($params['lookup'] ?? null) ? $params['lookup'] : [];

        switch ($params['status'] ?? null) {
            case 'published':
                return [...$params, 'lookup' => [...$lookup, 'publishedAt' => ['$notNull' => true]]];
            case 'draft':
                return [...$params, 'lookup' => [...$lookup, 'publishedAt' => ['$null' => true]]];
            default:
                break;
        }

        return [...$params, 'lookup' => $lookup];
    }

    /**
     * Translate publication status parameter into the data that will be saved.
     *
     * @param Schema|array<string, mixed> $contentType
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function statusToData(Schema|array $contentType, array $params): array
    {
        $data = is_array($params['data'] ?? null) ? $params['data'] : [];
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');

        if (!ContentTypes::hasDraftAndPublish($contentType)) {
            return [...$params, 'data' => [...$data, 'publishedAt' => $now]];
        }

        switch ($params['status'] ?? null) {
            case 'published':
                return [...$params, 'data' => [...$data, 'publishedAt' => $now]];
            case 'draft':
                return [...$params, 'data' => [...$data, 'publishedAt' => null]];
            default:
                break;
        }

        return $params;
    }
}
