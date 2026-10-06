<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Errors\PaginationError;

/**
 * Port of packages/core/utils/src/pagination.ts.
 *
 * @phpstan-type PaginationArgs array{page?: int|null, pageSize?: int|null, start?: int|null, limit?: int|null}
 * @phpstan-type Defaults array{offset?: array{start?: int, limit?: int}, page?: array{page?: int, pageSize?: int}}
 * @phpstan-type PagedInfo array{page: int, pageSize: int, pageCount: int, total: int}
 * @phpstan-type OffsetInfo array{start: int, limit: int, total: int}
 */
final class Pagination
{
    private const STRAPI_DEFAULTS = [
        'offset' => ['start' => 0, 'limit' => 10],
        'page' => ['page' => 1, 'pageSize' => 10],
    ];

    private const PAGINATION_ATTRIBUTES = ['start', 'limit', 'page', 'pageSize'];

    private static function withMaxLimit(int $limit, int $maxLimit = -1): int
    {
        if ($maxLimit === -1 || $limit < $maxLimit) {
            return $limit;
        }

        return $maxLimit;
    }

    /**
     * @param array{start: int, limit: int} $pagination
     * @return array{start: int, limit: int}
     */
    private static function ensureMinValues(array $pagination): array
    {
        return [
            'start' => max($pagination['start'], 0),
            'limit' => $pagination['limit'] === -1 ? -1 : max($pagination['limit'], 1),
        ];
    }

    /**
     * @param array{start: int, limit: int} $pagination
     * @return array{start: int, limit: int}
     */
    private static function ensureValidValues(array $pagination, int $maxLimit): array
    {
        $min = self::ensureMinValues($pagination);

        return ['start' => $min['start'], 'limit' => self::withMaxLimit($min['limit'], $maxLimit)];
    }

    /**
     * Resolve page/pageSize or start/limit into `{ start, limit }` keeping every other key of $args.
     *
     * @param array<string, mixed> $args
     * @param Defaults $defaults
     * @return array<string, mixed>  $args minus pagination keys, plus start & limit
     */
    public static function withDefaultPagination(array $args, array $defaults = [], int $maxLimit = -1): array
    {
        $defaultValues = array_replace_recursive(self::STRAPI_DEFAULTS, $defaults);

        $usePagePagination = ($args['page'] ?? null) !== null || ($args['pageSize'] ?? null) !== null;
        $useOffsetPagination = ($args['start'] ?? null) !== null || ($args['limit'] ?? null) !== null;

        if (!$usePagePagination && !$useOffsetPagination) {
            /** @var array{start: int, limit: int} $offsetDefaults */
            $offsetDefaults = $defaultValues['offset'];

            return array_merge($args, self::ensureValidValues($offsetDefaults, $maxLimit));
        }

        if ($usePagePagination && $useOffsetPagination) {
            throw new PaginationError('Cannot use both page & offset pagination in the same query');
        }

        $pagination = ['start' => 0, 'limit' => 0];

        if ($useOffsetPagination) {
            $merged = array_merge($defaultValues['offset'], array_filter(
                ['start' => $args['start'] ?? null, 'limit' => $args['limit'] ?? null],
                static fn (mixed $v): bool => $v !== null,
            ));
            $pagination = ['start' => (int) $merged['start'], 'limit' => (int) $merged['limit']];
        }

        if ($usePagePagination) {
            $pageArgs = ['page' => $args['page'] ?? null];
            if (($args['pageSize'] ?? null) !== null) {
                $pageArgs['pageSize'] = max(1, (int) $args['pageSize']);
            }
            $merged = array_merge($defaultValues['page'], array_filter($pageArgs, static fn (mixed $v): bool => $v !== null));
            $page = (int) $merged['page'];
            $pageSize = (int) $merged['pageSize'];
            $pageLimit = self::ensureValidValues(['start' => 0, 'limit' => $pageSize], $maxLimit)['limit'];

            $pagination = ['start' => ($page - 1) * $pageLimit, 'limit' => $pageLimit];
        }

        // Handle -1 limit
        if ($pagination['limit'] === -1) {
            $pagination['limit'] = $maxLimit;
        }

        $rest = array_diff_key($args, array_flip(self::PAGINATION_ATTRIBUTES));

        return array_merge(self::ensureValidValues($pagination, $maxLimit), $rest);
    }

    /**
     * @param PaginationArgs $paginationInfo
     * @return PagedInfo
     */
    public static function transformPagedPaginationInfo(array $paginationInfo, int $total): array
    {
        if (($paginationInfo['page'] ?? null) !== null) {
            $page = $paginationInfo['page'];
            $pageSize = $paginationInfo['pageSize'] ?? $total;

            return [
                'page' => $page,
                'pageSize' => $pageSize,
                'pageCount' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'total' => $total,
            ];
        }

        if (($paginationInfo['start'] ?? null) !== null) {
            $start = $paginationInfo['start'];
            $limit = $paginationInfo['limit'] ?? $total;

            return [
                'page' => $limit > 0 ? (int) floor($start / $limit) + 1 : 1,
                'pageSize' => $limit,
                'pageCount' => $limit > 0 ? (int) ceil($total / $limit) : 0,
                'total' => $total,
            ];
        }

        return [
            'page' => 1,
            'pageSize' => 10,
            'pageCount' => 1,
            'total' => $total,
        ];
    }

    /**
     * @param PaginationArgs $paginationInfo
     * @return OffsetInfo
     */
    public static function transformOffsetPaginationInfo(array $paginationInfo, int $total): array
    {
        if (($paginationInfo['page'] ?? null) !== null) {
            $limit = $paginationInfo['pageSize'] ?? $total;
            $start = ($paginationInfo['page'] - 1) * $limit;

            return ['start' => $start, 'limit' => $limit, 'total' => $total];
        }

        if (($paginationInfo['start'] ?? null) !== null) {
            return [
                'start' => $paginationInfo['start'],
                'limit' => $paginationInfo['limit'] ?? $total,
                'total' => $total,
            ];
        }

        return ['start' => 0, 'limit' => 10, 'total' => $total];
    }
}
