<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Utils\ContentApiConstants;

/** Port of services/document-service/params.ts. */
final class Params
{
    /** Keys passed to the query-params transformer. = SHARED_QUERY_PARAM_KEYS + withCount. */
    public const ALLOWED_DOCUMENT_PARAM_KEYS = [...ContentApiConstants::SHARED_QUERY_PARAM_KEYS, 'withCount'];

    /** Keys allowed at root when strictParams is true. */
    public const ALLOWED_DOCUMENT_ROOT_PARAM_KEYS = [...self::ALLOWED_DOCUMENT_PARAM_KEYS, 'data', 'pagination', 'count', 'ordering'];

    /**
     * Restrict to allowed query keys so only these reach the query-params transformer (security).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function pickAllowedQueryParams(array $params): array
    {
        return array_intersect_key($params, array_flip(self::ALLOWED_DOCUMENT_PARAM_KEYS));
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public static function pickSelectionParams(?array $data): array
    {
        return array_intersect_key($data ?? [], array_flip(['fields', 'populate', 'status']));
    }

    public static function isParamEmpty(mixed $v): bool
    {
        return $v === null || $v === '';
    }
}
