<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Service;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Pagination as PaginationUtils;

/**
 * Port of core-api/service/pagination.ts.
 *
 * @phpstan-type PaginationParams array{withCount?: mixed, page?: mixed, pageSize?: mixed, start?: mixed, limit?: mixed}
 * @phpstan-type PaginationInfo array{page?: int, pageSize?: int, start?: int, limit?: int}
 */
final class Pagination
{
    /** @return array{defaultLimit: int, maxLimit: int|null} */
    private static function getLimitConfigDefaults(Strapi $strapi): array
    {
        $maxLimit = $strapi->config()->get('api.rest.maxLimit');

        return [
            'defaultLimit' => (int) $strapi->config()->get('api.rest.defaultLimit', 25),
            'maxLimit' => is_numeric($maxLimit) && (int) $maxLimit !== 0 ? (int) $maxLimit : null,
        ];
    }

    /** @param PaginationParams|null $pagination */
    private static function isOffsetPagination(?array $pagination): bool
    {
        return is_array($pagination) && (array_key_exists('start', $pagination) || array_key_exists('limit', $pagination));
    }

    /** @param PaginationParams|null $pagination */
    public static function isPagedPagination(?array $pagination): bool
    {
        return (is_array($pagination) && (array_key_exists('page', $pagination) || array_key_exists('pageSize', $pagination))) || !self::isOffsetPagination($pagination);
    }

    /** @param array{pagination?: PaginationParams|mixed} $params */
    public static function shouldCount(Strapi $strapi, array $params): bool
    {
        if (is_array($params['pagination'] ?? null) && array_key_exists('withCount', $params['pagination'])) {
            $withCount = $params['pagination']['withCount'];

            if (is_bool($withCount)) {
                return $withCount;
            }

            if ($withCount === null) {
                return false;
            }

            if (in_array($withCount, ['true', 't', '1', 1], true)) {
                return true;
            }

            if (in_array($withCount, ['false', 'f', '0', 0], true)) {
                return false;
            }

            throw new ValidationError('Invalid withCount parameter. Expected "t","1","true","false","0","f"');
        }

        return (bool) $strapi->config()->get('api.rest.withCount', true);
    }

    /**
     * @param array{pagination?: PaginationParams|mixed} $params
     * @return array{start: int, limit: int}
     */
    public static function getPaginationInfo(Strapi $strapi, array $params): array
    {
        ['defaultLimit' => $defaultLimit, 'maxLimit' => $maxLimit] = self::getLimitConfigDefaults($strapi);

        $pagination = is_array($params['pagination'] ?? null) ? $params['pagination'] : [];
        $result = PaginationUtils::withDefaultPagination($pagination, [
            'offset' => ['limit' => $defaultLimit],
            'page' => ['pageSize' => $defaultLimit],
        ], $maxLimit ?? -1);

        return ['start' => (int) $result['start'], 'limit' => (int) $result['limit']];
    }

    /**
     * @param PaginationInfo $paginationInfo
     * @return array<string, int>
     */
    public static function transformPaginationResponse(array $paginationInfo, ?int $total, bool $isPaged): array
    {
        $paginationResponse = $isPaged
            ? PaginationUtils::transformPagedPaginationInfo($paginationInfo, $total ?? 0)
            : PaginationUtils::transformOffsetPaginationInfo($paginationInfo, $total ?? 0);

        if ($total === null) {
            // Ignore total and pageCount if `total` value is not available.
            unset($paginationResponse['total'], $paginationResponse['pageCount']);
        }

        return $paginationResponse;
    }
}
