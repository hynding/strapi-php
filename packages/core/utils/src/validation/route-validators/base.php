<?php

declare(strict_types=1);

namespace Strapi\Utils\Validation\RouteValidators;

use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/utils/src/validation/route-validators/base.ts: the foundation for
 * validating routes, with the common query parameter validators reused by route validators
 * (plugins, external packages) and the schema-aware core content-type validators. Upstream's
 * getters are methods.
 */
abstract class AbstractRouteValidator
{
    /** Creates a fields query parameter validator (field selection for API responses) */
    public function queryFields(): ZodType
    {
        return QueryParams::queryFieldsSchema();
    }

    /** Creates a populate query parameter validator (which relations to populate in the response) */
    public function queryPopulate(): ZodType
    {
        return QueryParams::queryPopulateSchema();
    }

    /** Creates a sort query parameter validator (sorting options for list endpoints) */
    public function querySort(): ZodType
    {
        return QueryParams::querySortSchema();
    }

    /** Creates a pagination query parameter validator (page-based and offset-based) */
    public function pagination(): ZodType
    {
        return QueryParams::paginationSchema();
    }

    /** Creates a filters query parameter validator (filtering options for list endpoints) */
    public function filters(): ZodType
    {
        return QueryParams::filtersSchema();
    }

    /** Creates a locale query parameter validator (internationalization) */
    public function locale(): ZodType
    {
        return QueryParams::localeSchema();
    }

    /** Creates a status query parameter validator (draft & publish) */
    public function status(): ZodType
    {
        return QueryParams::statusSchema();
    }

    /** Creates a search query parameter validator (text search) */
    public function query(): ZodType
    {
        return QueryParams::searchQuerySchema();
    }

    /**
     * Provides access to all base query parameter validators.
     *
     * @return array<string, \Closure(): ZodType>
     */
    protected function baseQueryValidators(): array
    {
        return [
            'fields' => fn (): ZodType => $this->queryFields()->optional(),
            'populate' => fn (): ZodType => $this->queryPopulate()->optional(),
            'sort' => fn (): ZodType => $this->querySort()->optional(),
            'filters' => fn (): ZodType => $this->filters()->optional(),
            'pagination' => fn (): ZodType => $this->pagination()->optional(),
            'locale' => fn (): ZodType => $this->locale()->optional(),
            'status' => fn (): ZodType => $this->status()->optional(),
            '_q' => fn (): ZodType => $this->query()->optional(),
        ];
    }

    /**
     * Helper method to create a query parameters object with specified validators.
     *
     * @param list<string> $params query parameter names to include
     *
     * @return array<string, ZodType> Zod schemas for the requested query parameters
     */
    public function queryParams(array $params): array
    {
        $validators = $this->baseQueryValidators();

        $acc = [];
        foreach ($params as $param) {
            if (isset($validators[$param])) {
                $acc[$param] = $validators[$param]();
            }
        }

        return $acc;
    }
}
