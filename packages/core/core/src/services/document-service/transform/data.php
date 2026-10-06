<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform;

use Strapi\Core\Services\DocumentService\Transform\Relations\Extract\DataIds as ExtractDataIds;
use Strapi\Core\Services\DocumentService\Transform\Relations\Transform\DataIds as TransformDataIds;
use Strapi\Core\Services\DocumentService\Transform\Relations\Transform\DefaultLocale;
use Strapi\Core\Strapi;

/**
 * Port of transform/data.ts: transforms input data containing relation document ids to entity ids.
 * The id map is cached on the request state (`__documentServiceIdMap`) so repeated calls in one
 * request reuse it, unless `useRequestCache: false`.
 *
 * @phpstan-import-type Options from Types
 */
final class Data
{
    private static function getRequestState(Strapi $strapi): ?\Strapi\Types\Core\State
    {
        return $strapi->requestContext()->get()?->state();
    }

    public static function clearTransformDataRequestCache(Strapi $strapi): void
    {
        $state = self::getRequestState($strapi);
        $idMap = $state?->get('__documentServiceIdMap');
        if ($idMap instanceof IdMap) {
            $idMap->clear();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param Options $opts
     * @return array<string, mixed>
     */
    public static function transformData(Strapi $strapi, array $data, array $opts): array
    {
        $shouldUseRequestCache = ($opts['useRequestCache'] ?? true) !== false;

        // Store cache on request state so repeated calls in one request can reuse it
        $requestState = $shouldUseRequestCache ? self::getRequestState($strapi) : null;

        if ($requestState !== null && !($requestState->get('__documentServiceIdMap') instanceof IdMap)) {
            $requestState->set('__documentServiceIdMap', IdMap::createIdMap($strapi));
        }

        $idMap = $requestState?->get('__documentServiceIdMap');
        if (!$idMap instanceof IdMap) {
            $idMap = IdMap::createIdMap($strapi);
        }

        // Assign default locales
        $transformedData = DefaultLocale::setDefaultLocaleToRelations($strapi, $data, $opts['uid']);
        $transformedData = is_array($transformedData) ? $transformedData : $data;

        // Extract any relation ids from the input
        ExtractDataIds::extractDataIds($strapi, $idMap, $transformedData, $opts);

        // Load any relation the extract methods found
        $idMap->load();

        // Transform any relation ids to entity ids
        $result = TransformDataIds::transformDataIdsVisitor($strapi, $idMap, $transformedData, $opts);

        return is_array($result) ? $result : $transformedData;
    }
}
