<?php

declare(strict_types=1);

namespace Strapi\Utils\Validation\RouteValidators;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/utils/src/validation/route-validators/query-params.ts: standard query
 * parameter validators reusable across route validators (building blocks for both generic and
 * schema-aware validation). Upstream exports module-level schema constants; here each is a static
 * method returning the same (memoized) schema.
 *
 * @phpstan-type QueryParam 'fields'|'populate'|'sort'|'pagination'|'filters'|'locale'|'status'|'_q'
 */
final class QueryParams
{
    /** @var array<string, ZodType> */
    private static array $schemas = [];

    /** Fields parameter validation. Supports: 'title', ['title', 'name'], or '*' */
    public static function queryFieldsSchema(): ZodType
    {
        return self::$schemas['fields'] ??= z::union([z::string(), z::array(z::string())])
            ->describe('Select specific fields to return in the response');
    }

    /** Populate parameter validation. Supports: '*', 'relation', ['relation1', 'relation2'], or complex objects */
    public static function queryPopulateSchema(): ZodType
    {
        return self::$schemas['populate'] ??= z::union([z::literal('*'), z::string(), z::array(z::string()), z::record(z::string(), z::any())])
            ->describe('Specify which relations to populate in the response');
    }

    /** Sort parameter validation. Supports: 'name', ['name', 'title'], { name: 'asc' }, or [{ name: 'desc' }] */
    public static function querySortSchema(): ZodType
    {
        return self::$schemas['sort'] ??= z::union([
            z::string(),
            z::array(z::string()),
            z::record(z::string(), z::enum(['asc', 'desc'])),
            z::array(z::record(z::string(), z::enum(['asc', 'desc']))),
        ])->describe('Sort the results by specified fields');
    }

    /** Pagination parameter validation. Supports both page-based and offset-based pagination */
    public static function paginationSchema(): ZodType
    {
        return self::$schemas['pagination'] ??= z::intersection(
            z::object([
                'withCount' => z::boolean()->optional()->describe('Include total count in response'),
            ]),
            z::union([
                z::object([
                    'page' => z::number()->int()->positive()->describe('Page number (1-based)'),
                    'pageSize' => z::number()->int()->positive()->describe('Number of entries per page'),
                ])->describe('Page-based pagination'),
                z::object([
                    'start' => z::number()->int()->min(0)->describe('Number of entries to skip'),
                    'limit' => z::number()->int()->positive()->describe('Maximum number of entries to return'),
                ])->describe('Offset-based pagination'),
            ]),
        )->describe('Pagination parameters');
    }

    /** Filters parameter validation. Supports any object structure for filtering */
    public static function filtersSchema(): ZodType
    {
        return self::$schemas['filters'] ??= z::record(z::string(), z::any())->describe('Apply filters to the query');
    }

    /** Locale parameter validation. Used for internationalization */
    public static function localeSchema(): ZodType
    {
        return self::$schemas['locale'] ??= z::string()->describe('Specify the locale for localized content');
    }

    /** Status parameter validation. Used for draft & publish functionality */
    public static function statusSchema(): ZodType
    {
        return self::$schemas['status'] ??= z::enum(['draft', 'published'])->describe('Filter by publication status');
    }

    /** Search query parameter validation. Used for text search functionality */
    public static function searchQuerySchema(): ZodType
    {
        return self::$schemas['_q'] ??= z::string()->describe('Search query string');
    }

    /**
     * Complete collection of all standard query parameter schemas.
     *
     * @return array<string, ZodType>
     */
    public static function queryParameterSchemas(): array
    {
        return [
            'fields' => self::queryFieldsSchema(),
            'populate' => self::queryPopulateSchema(),
            'sort' => self::querySortSchema(),
            'pagination' => self::paginationSchema(),
            'filters' => self::filtersSchema(),
            'locale' => self::localeSchema(),
            'status' => self::statusSchema(),
            '_q' => self::searchQuerySchema(),
        ];
    }
}
