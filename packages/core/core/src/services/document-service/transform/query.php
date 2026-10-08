<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform;

use Strapi\Core\Services\DocumentService\Params;
use Strapi\Core\Services\DocumentService\PublicationFilter as DocPublicationFilter;
use Strapi\Core\Strapi;
use Strapi\Utils\HasPublishedVersionParam;
use Strapi\Utils\PublicationFilter;

/**
 * Port of transform/query.ts: document service params → database query (via `query-params`),
 * merging `lookup` into `where` and wrapping `filters` with the publication filter cohort logic.
 */
final class Query
{
    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    public static function transformParamsToQuery(Strapi $strapi, string $uid, ?array $params): array
    {
        $rawParams = $params ?? [];
        $allowlisted = Params::pickAllowedQueryParams($rawParams);
        $query = $strapi->get('query-params')->transform($uid, $allowlisted);

        $explicitPublicationFilter = PublicationFilter::parsePublicationFilter($allowlisted['publicationFilter'] ?? null);
        $legacyHasPublishedVersion = HasPublishedVersionParam::parseHasPublishedVersionQueryParam($rawParams['hasPublishedVersion'] ?? null);

        $effectivePublicationFilter = $explicitPublicationFilter;
        if ($effectivePublicationFilter === null && $legacyHasPublishedVersion !== null) {
            $effectivePublicationFilter = HasPublishedVersionParam::hasPublishedVersionBooleanToPublicationFilterMode($legacyHasPublishedVersion);
        }

        $status = ($allowlisted['status'] ?? null) === 'published' ? 'published' : 'draft';

        $baseWhere = [...(is_array($rawParams['lookup'] ?? null) ? $rawParams['lookup'] : []), ...(is_array($query['where'] ?? null) ? $query['where'] : [])];

        // `transformQueryParams` leaves `publicationFilter` / `hasPublishedVersion` on the query object
        unset($query['publicationFilter'], $query['hasPublishedVersion']);

        // Publication filtering must go through `query.filters`, not only `where`, so the same cohort
        // logic applies to nested populate queries (each sub-query uses `meta.uid`).
        if ($effectivePublicationFilter !== null) {
            $existingFilters = $query['filters'] ?? null;

            $wrappedFilters = static function (array $ctx) use ($existingFilters, $strapi, $effectivePublicationFilter, $status): array {
                $meta = $ctx['meta'] ?? [];
                $existingResult = [];
                if (is_callable($existingFilters)) {
                    $existingResult = $existingFilters($ctx) ?: [];
                } elseif (is_array($existingFilters)) {
                    $existingResult = $existingFilters;
                }

                $metaUid = is_array($meta) ? ($meta['uid'] ?? null) : null;
                $publicationCondition = is_string($metaUid) ? DocPublicationFilter::getPublicationFilterCondition($strapi, $metaUid, $effectivePublicationFilter, $status) : null;

                if ($publicationCondition !== null && $publicationCondition !== []) {
                    $conditions = array_values(array_filter([$existingResult, $publicationCondition], static fn (array $c): bool => $c !== []));

                    return ['$and' => $conditions];
                }

                return $existingResult;
            };

            return [...$query, 'filters' => $wrappedFilters, 'where' => $baseWhere];
        }

        return [...$query, 'where' => $baseWhere];
    }
}
