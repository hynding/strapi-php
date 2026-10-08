<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\PaginationError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Objects;

/**
 * The transformer returned by {@see ConvertQueryParams::createTransformer()}. Method names match
 * upstream's closures (`private_convertSortQueryParams` → `convertSortQueryParams`).
 *
 * Query shape produced by `transformQueryParams`:
 * `{ select?, where?, orderBy?, populate?, filters?: callable(array{meta}): array, _q?, count?, ordering?, page?, pageSize?, offset?, limit?, ...rest }`.
 *
 * @phpstan-type Model Schema|array<string, mixed>
 * @phpstan-type Query array<string, mixed>
 */
final class QueryParamsTransformer
{
    /** @var \Closure(string): (Schema|array<string, mixed>|null) */
    private readonly \Closure $getModel;

    /** @param callable(string): (Schema|array<string, mixed>|null) $getModel */
    public function __construct(callable $getModel)
    {
        $this->getModel = $getModel(...);
    }

    /** @return Schema|array<string, mixed>|null */
    public function getModel(string $uid): Schema|array|null
    {
        return ($this->getModel)($uid);
    }

    private static function invalidOrderError(): ValidationError
    {
        return new ValidationError('Invalid order. order can only be one of asc|desc|ASC|DESC');
    }

    private static function invalidSortError(): ValidationError
    {
        return new ValidationError('Invalid sort parameter. Expected a string, an array of strings, a sort object or an array of sort objects');
    }

    private static function invalidPopulateError(): ValidationError
    {
        return new ValidationError('Invalid populate parameter. Expected a string, an array of strings, a populate object');
    }

    private static function validateOrder(mixed $order): void
    {
        if (!is_string($order) || !in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw self::invalidOrderError();
        }
    }

    private static function isStringArray(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $v) {
            if (!is_string($v)) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------------------------------
    // sort
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function convertSortQueryParams(mixed $sortQuery): array
    {
        if (is_string($sortQuery)) {
            return $this->convertStringSortQueryParam($sortQuery);
        }

        if (self::isStringArray($sortQuery)) {
            $out = [];
            foreach ($sortQuery as $sortValue) {
                foreach ($this->convertStringSortQueryParam($sortValue) as $map) {
                    $out[] = $map;
                }
            }

            return $out;
        }

        if (is_array($sortQuery) && array_is_list($sortQuery) && $sortQuery !== []) {
            $out = [];
            foreach ($sortQuery as $sortValue) {
                if (!is_array($sortValue)) {
                    throw self::invalidSortError();
                }
                $out[] = $this->convertNestedSortQueryParam($sortValue);
            }

            return $out;
        }

        if (is_array($sortQuery)) {
            return $this->convertNestedSortQueryParam($sortQuery);
        }

        throw self::invalidSortError();
    }

    /** @return list<array<string, mixed>> */
    private function convertStringSortQueryParam(string $sortQuery): array
    {
        return array_map($this->convertSingleSortQueryParam(...), SortQuery::getMeaningfulSortSegments($sortQuery));
    }

    /** @return array<string, mixed> */
    private function convertSingleSortQueryParam(string $sortQuery): array
    {
        $trimmed = trim($sortQuery);

        if ($trimmed === '') {
            return [];
        }

        // split field and order param with default order to ascending
        $parts = explode(':', $trimmed);
        $field = trim($parts[0]);
        $order = $parts[1] ?? 'asc';

        if ($field === '') {
            throw new ValidationError('Field cannot be empty');
        }

        self::validateOrder(trim($order));

        return ConvertQueryParams::setSortMapValue($field, trim($order));
    }

    /**
     * @param array<string, mixed> $sortQuery
     * @return array<string, mixed>
     */
    private function convertNestedSortQueryParam(array $sortQuery): array
    {
        $transformedSort = [];
        foreach ($sortQuery as $field => $order) {
            // this is a deep sort
            if (is_array($order) && !array_is_list($order)) {
                $nested = $this->convertNestedSortQueryParam($order);

                if (!ConvertQueryParams::isEmptySortMap($nested)) {
                    $transformedSort[$field] = $nested;
                }
            } elseif (is_array($order) && $order === []) {
                continue;
            } elseif (is_string($order)) {
                $trimmedOrder = trim($order);

                if ($trimmedOrder !== '') {
                    self::validateOrder($trimmedOrder);
                    $transformedSort[$field] = $trimmedOrder;
                }
            } else {
                throw new ValidationError('Invalid sort type expected object or string got ' . ParseType::typeOf($order));
            }
        }

        return $transformedSort;
    }

    /** @param Query $query */
    private function applySortToQuery(array &$query, mixed $sortParam): void
    {
        if (!SortQuery::hasSort($sortParam)) {
            return;
        }

        $orderBy = ConvertQueryParams::normalizeOrderBy($this->convertSortQueryParams($sortParam));

        if ($orderBy !== null) {
            $query['orderBy'] = $orderBy;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // pagination
    // ---------------------------------------------------------------------------------------------

    public function convertStartQueryParams(mixed $startQuery): int
    {
        // null stands for JavaScript's `undefined` here (an absent param), which `toNumber` turns into NaN
        $startAsANumber = $startQuery === null ? NAN : ParseType::toNumber($startQuery);

        if (!self::isInteger($startAsANumber) || $startAsANumber < 0) {
            throw new ValidationError('convertStartQueryParams expected a positive integer got ' . Primitives\Strings::stringify($startAsANumber));
        }

        return (int) $startAsANumber;
    }

    public function convertLimitQueryParams(mixed $limitQuery): ?int
    {
        $limitAsANumber = $limitQuery === null ? NAN : ParseType::toNumber($limitQuery);

        if (!self::isInteger($limitAsANumber) || ($limitAsANumber != -1 && $limitAsANumber < 0)) {
            throw new ValidationError('convertLimitQueryParams expected a positive integer got ' . Primitives\Strings::stringify($limitAsANumber));
        }

        if ($limitAsANumber == -1) {
            return null;
        }

        return (int) $limitAsANumber;
    }

    public function convertPageQueryParams(mixed $page): int
    {
        $pageVal = ParseType::toNumber($page);

        if (!self::isInteger($pageVal) || $pageVal <= 0) {
            throw new PaginationError("Invalid 'page' parameter. Expected an integer > 0, received: " . Primitives\Strings::stringify($page));
        }

        return (int) $pageVal;
    }

    public function convertPageSizeQueryParams(mixed $pageSize, mixed $page = null): int
    {
        $pageSizeVal = ParseType::toNumber($pageSize);

        if (!self::isInteger($pageSizeVal) || $pageSizeVal <= 0) {
            throw new PaginationError("Invalid 'pageSize' parameter. Expected an integer > 0, received: " . Primitives\Strings::stringify($pageSize));
        }

        return (int) $pageSizeVal;
    }

    private static function isInteger(int|float $value): bool
    {
        return is_int($value) || (is_finite($value) && floor($value) === $value);
    }

    private function validatePaginationParams(mixed $page, mixed $pageSize, mixed $start, mixed $limit): void
    {
        $isPagePagination = $page !== null || $pageSize !== null;
        $isOffsetPagination = $start !== null || $limit !== null;

        if ($isPagePagination && $isOffsetPagination) {
            throw new PaginationError('Invalid pagination attributes. The page parameters are incorrect and must be in the pagination object');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // populate
    // ---------------------------------------------------------------------------------------------

    /**
     * @param Model|null $schema
     * @return true|list<string>|array<string, mixed>
     */
    public function convertPopulateQueryParams(mixed $populate, Schema|array|null $schema = null, int $depth = 0): bool|array
    {
        if ($depth === 0 && $populate === '*') {
            return true;
        }

        if (is_string($populate)) {
            return array_map('trim', explode(',', $populate));
        }

        if (is_array($populate) && array_is_list($populate) && $populate !== []) {
            $out = [];
            foreach ($populate as $value) {
                if (!is_string($value)) {
                    throw self::invalidPopulateError();
                }
                foreach (explode(',', $value) as $part) {
                    $out[] = trim($part);
                }
            }

            return array_values(array_unique($out));
        }

        if (is_array($populate)) {
            return $this->convertPopulateObject($populate, $schema);
        }

        throw self::invalidPopulateError();
    }

    /** @param array<string, mixed> $populate */
    private static function hasPopulateFragmentDefined(array $populate): bool
    {
        return array_key_exists('on', $populate) && $populate['on'] !== null;
    }

    /** @param array<string, mixed> $populate */
    private static function hasCountDefined(array $populate): bool
    {
        return array_key_exists('count', $populate) && is_bool($populate['count']);
    }

    /**
     * @param array<string, mixed> $populate
     * @param Model|null $schema
     * @return array<string, mixed>
     */
    private function convertPopulateObject(array $populate, Schema|array|null $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $acc = [];
        foreach ($populate as $key => $subPopulate) {
            $key = (string) $key;

            // Try converting strings to regular booleans if possible
            if (is_string($subPopulate)) {
                if (ParseType::isBooleanLike($subPopulate)) {
                    // Only true is accepted as a boolean populate value
                    if (ParseType::parseBoolean($subPopulate)) {
                        $acc[$key] = true;
                    }
                    continue;
                }
            }

            if (is_bool($subPopulate)) {
                if ($subPopulate === true) {
                    $acc[$key] = true;
                }
                continue;
            }

            $attribute = ContentTypes::attribute($schema, $key);

            if ($attribute === null) {
                continue;
            }

            // Allow adding an 'on' strategy to populate queries for morphTo relations and dynamic zones
            $isMorphLikeRelationalAttribute = ContentTypes::isDynamicZoneAttribute($attribute) || ContentTypes::isMorphToRelationalAttribute($attribute);

            if ($isMorphLikeRelationalAttribute) {
                $subKeys = is_array($subPopulate) ? array_map('strval', array_keys($subPopulate)) : [];
                $hasInvalidProperties = !is_array($subPopulate) || array_filter($subKeys, static fn (string $k): bool => !in_array($k, ['populate', 'on', 'count'], true)) !== [];

                if ($hasInvalidProperties) {
                    $info = ContentTypes::info($schema);
                    $singularName = $info['singularName'] ?? null;
                    $uid = ContentTypes::uid($schema);
                    throw new ValidationError(sprintf(
                        'Invalid nested populate for %s.%s (%s). Expected a fragment ("on") or "count" but found %s',
                        is_string($singularName) ? $singularName : 'undefined',
                        $key,
                        $uid ?? 'undefined',
                        self::jsonStringify($subPopulate),
                    ));
                }

                /** @var array<string, mixed> $subPopulate */
                if (array_key_exists('populate', $subPopulate) && $subPopulate['populate'] !== null && $subPopulate['populate'] !== '*') {
                    throw new ValidationError(
                        "Invalid nested population query detected. When using 'populate' within polymorphic structures, "
                        . "its value must be '*' to indicate all second level links. Specific field targeting is not supported here. "
                        . 'Consider using the fragment API for more granular population control.'
                    );
                }

                $newSubPopulate = [];

                // case: { populate: '*' }
                if (array_key_exists('populate', $subPopulate) && $subPopulate['populate'] === '*') {
                    $newSubPopulate['populate'] = true;
                }

                // case: { on: { <clauses> } }
                if (self::hasPopulateFragmentDefined($subPopulate)) {
                    $on = [];
                    foreach ((array) $subPopulate['on'] as $type => $typeSubPopulate) {
                        $on[$type] = $this->convertNestedPopulate($typeSubPopulate, $this->getModel((string) $type));
                    }
                    $newSubPopulate['on'] = $on;
                }

                // case: { count: true | false }
                if (self::hasCountDefined($subPopulate)) {
                    $newSubPopulate['count'] = $subPopulate['count'];
                }

                $acc[$key] = $newSubPopulate;
                continue;
            }

            // Edge case when trying to use the fragment ('on') on a non-morph like attribute
            if (is_array($subPopulate) && self::hasPopulateFragmentDefined($subPopulate)) {
                throw new ValidationError(sprintf('Using fragments is not permitted to populate "%s" in "%s"', $key, ContentTypes::uid($schema) ?? ''));
            }

            // Retrieve the target schema UID (basic relations, medias and components only)
            $type = $attribute['type'] ?? null;
            if ($type === 'relation') {
                $targetSchemaUID = $attribute['target'] ?? null;
            } elseif ($type === 'component') {
                $targetSchemaUID = $attribute['component'] ?? null;
            } elseif ($type === 'media') {
                $targetSchemaUID = 'plugin::upload.file';
            } else {
                continue;
            }

            $targetSchema = is_string($targetSchemaUID) ? $this->getModel($targetSchemaUID) : null;

            // ignore the sub-populate for the current key if there is no schema associated
            if ($targetSchema === null) {
                continue;
            }

            $populateObject = $this->convertNestedPopulate($subPopulate, $targetSchema);

            if ($populateObject === false) {
                continue;
            }

            $acc[$key] = $populateObject;
        }

        return $acc;
    }

    /**
     * @param Model|null $schema
     * @return bool|array<string, mixed>
     */
    private function convertNestedPopulate(mixed $subPopulate, Schema|array|null $schema): bool|array
    {
        if (is_string($subPopulate)) {
            return ParseType::parseBoolean($subPopulate, true);
        }

        if (is_bool($subPopulate)) {
            return $subPopulate;
        }

        if (!is_array($subPopulate) || (array_is_list($subPopulate) && $subPopulate !== [])) {
            throw new ValidationError("Invalid nested populate. Expected '*' or an object");
        }

        $sort = $subPopulate['sort'] ?? null;
        $filters = $subPopulate['filters'] ?? null;
        $fields = $subPopulate['fields'] ?? null;
        $populate = $subPopulate['populate'] ?? null;
        $count = $subPopulate['count'] ?? null;
        $ordering = $subPopulate['ordering'] ?? null;
        $page = $subPopulate['page'] ?? null;
        $pageSize = $subPopulate['pageSize'] ?? null;
        $start = $subPopulate['start'] ?? null;
        $limit = $subPopulate['limit'] ?? null;

        $query = [];

        $this->applySortToQuery($query, $sort);

        if ($filters) {
            $query['where'] = $this->convertFiltersQueryParams($filters, $schema);
        }

        if ($fields) {
            $query['select'] = $this->convertFieldsQueryParams($fields, $schema);
        }

        if ($populate) {
            $query['populate'] = $this->convertPopulateQueryParams($populate, $schema);
        }

        if ($count) {
            $query['count'] = ParseType::parseBoolean($count);
        }

        if ($ordering) {
            $query['ordering'] = $ordering;
        }

        $this->validatePaginationParams($page, $pageSize, $start, $limit);

        if ($page !== null) {
            $query['page'] = $this->convertPageQueryParams($page);
        }

        if ($pageSize !== null) {
            $query['pageSize'] = $this->convertPageSizeQueryParams($pageSize, $page);
        }

        if ($start !== null) {
            $query['offset'] = $this->convertStartQueryParams($start);
        }

        if ($limit !== null) {
            $query['limit'] = $this->convertLimitQueryParams($limit);
        }

        return $query;
    }

    // ---------------------------------------------------------------------------------------------
    // fields
    // ---------------------------------------------------------------------------------------------

    /**
     * @param Model|null $schema
     * @return list<string>|null null for `*` at depth 0 (select everything)
     */
    public function convertFieldsQueryParams(mixed $fields, Schema|array|null $schema = null, int $depth = 0): ?array
    {
        if ($depth === 0 && $fields === '*') {
            return null;
        }

        if (is_string($fields)) {
            $fieldsValues = array_map('trim', explode(',', $fields));

            return $this->withIds($fieldsValues, $schema);
        }

        if (self::isStringArray($fields)) {
            $fieldsValues = [];
            foreach ($fields as $value) {
                $converted = $this->convertFieldsQueryParams($value, $schema, $depth + 1);
                foreach ($converted ?? [] as $v) {
                    $fieldsValues[] = $v;
                }
            }

            return $this->withIds($fieldsValues, $schema);
        }

        throw new ValidationError('Invalid fields parameter. Expected a string or an array of strings');
    }

    /**
     * @param list<string> $fieldsValues
     * @param Model|null $schema
     * @return list<string>
     */
    private function withIds(array $fieldsValues, Schema|array|null $schema): array
    {
        // NOTE: Only include the doc id if it's a content type
        if (ContentTypes::isContentTypeSchema($schema)) {
            return array_values(array_unique([ContentTypes::ID_ATTRIBUTE, ContentTypes::DOC_ID_ATTRIBUTE, ...$fieldsValues]));
        }

        return array_values(array_unique([ContentTypes::ID_ATTRIBUTE, ...$fieldsValues]));
    }

    // ---------------------------------------------------------------------------------------------
    // filters
    // ---------------------------------------------------------------------------------------------

    /** @param Model|null $schema */
    private function isValidSchemaAttribute(string $key, Schema|array|null $schema): bool
    {
        if (in_array($key, ContentTypes::ID_FIELDS, true)) {
            return true;
        }

        if ($schema === null) {
            return false;
        }

        return array_key_exists($key, ContentTypes::attributes($schema));
    }

    /**
     * @param Model|null $schema
     * @return array<string|int, mixed>
     */
    public function convertFiltersQueryParams(mixed $filters, Schema|array|null $schema = null): array
    {
        // Filters need to be either an array or an object
        if (!is_array($filters)) {
            throw new ValidationError('The filters parameter must be an object or an array');
        }

        return $this->convertAndSanitizeFilters($filters, $schema);
    }

    /** @param Model|null $schema */
    private function convertAndSanitizeFilters(mixed $filters, Schema|array|null $schema): mixed
    {
        if (is_array($filters) && array_is_list($filters) && $filters !== []) {
            $out = [];
            foreach ($filters as $filter) {
                $sanitized = $this->convertAndSanitizeFilters($filter, $schema);
                // Filter out empty filters
                if (is_array($sanitized) && $sanitized === []) {
                    continue;
                }
                $out[] = $sanitized;
            }

            return $out;
        }

        if (!is_array($filters)) {
            return $filters;
        }

        foreach ($filters as $key => $value) {
            $key = (string) $key;
            $attribute = ContentTypes::attribute($schema, $key);
            $validKey = Operators::isOperator($key) || $this->isValidSchemaAttribute($key, $schema);

            if (!$validKey) {
                unset($filters[$key]);
            } elseif ($attribute !== null) {
                $type = $attribute['type'] ?? null;

                if ($type === 'relation') {
                    $filters[$key] = $this->convertAndSanitizeFilters($value, $this->getModel((string) ($attribute['target'] ?? '')));
                } elseif ($type === 'component') {
                    $filters[$key] = $this->convertAndSanitizeFilters($value, $this->getModel((string) ($attribute['component'] ?? '')));
                } elseif ($type === 'media') {
                    $filters[$key] = $this->convertAndSanitizeFilters($value, $this->getModel('plugin::upload.file'));
                } elseif ($type === 'dynamiczone') {
                    unset($filters[$key]);
                } elseif ($type === 'password') {
                    // Always remove password attributes from filters object
                    unset($filters[$key]);
                } else {
                    // Scalar attributes
                    $filters[$key] = $this->convertAndSanitizeFilters($value, $schema);
                }
            } elseif (in_array($key, ['$null', '$notNull'], true)) {
                $filters[$key] = ParseType::parseBoolean($filters[$key], true);
            } elseif (is_array($value)) {
                $filters[$key] = $this->convertAndSanitizeFilters($value, $schema);
            }

            // Remove empty objects & arrays
            if (array_key_exists($key, $filters) && is_array($filters[$key]) && $filters[$key] === []) {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    // ---------------------------------------------------------------------------------------------
    // status / transform
    // ---------------------------------------------------------------------------------------------

    /**
     * Sets `query.filters` to a database-layer filter resolving the `publishedAt` condition for the
     * target model (`callable(array{meta: array|Schema}): array`).
     *
     * @param Query $query
     */
    public function convertStatusParams(?string $status, array &$query): void
    {
        $getModel = $this->getModel;

        $query['filters'] = static function (array $params) use ($status, $getModel): array {
            $meta = $params['meta'] ?? null;
            $uid = is_array($meta) ? ($meta['uid'] ?? null) : (is_object($meta) && property_exists($meta, 'uid') ? $meta->uid : null);
            $contentType = is_string($uid) ? $getModel($uid) : null;

            // Ignore if target model has disabled DP, as it doesn't make sense to filter by its status
            if ($contentType === null || !ContentTypes::hasDraftAndPublish($contentType)) {
                return [];
            }

            return [ContentTypes::PUBLISHED_AT_ATTRIBUTE => ['$null' => $status === 'draft']];
        };
    }

    /**
     * Alias for upstream's `convertPublicationStateParams` naming used by older callers.
     *
     * @param Query $query
     */
    public function convertPublicationStateParams(?string $status, array &$query): void
    {
        $this->convertStatusParams($status, $query);
    }

    /**
     * @param array<string, mixed> $params
     * @return Query
     */
    public function transformQueryParams(string $uid, array $params): array
    {
        // NOTE: can be a CT, a Compo or nothing in the case of polymorphism (DZ & morph relations)
        $schema = $this->getModel($uid);

        $query = [];

        $searchQuery = $params['_q'] ?? null;
        $sort = $params['sort'] ?? null;
        $filters = $params['filters'] ?? null;
        $fields = $params['fields'] ?? null;
        $populate = $params['populate'] ?? null;
        $page = $params['page'] ?? null;
        $pageSize = $params['pageSize'] ?? null;
        $start = $params['start'] ?? null;
        $limit = $params['limit'] ?? null;
        $status = $params['status'] ?? null;
        $rest = Objects::omit($params, ['_q', 'sort', 'filters', 'fields', 'populate', 'page', 'pageSize', 'start', 'limit', 'status']);

        if ($status !== null) {
            $this->convertStatusParams(is_string($status) ? $status : null, $query);
        }

        if ($searchQuery !== null) {
            $query['_q'] = $searchQuery;
        }

        $this->applySortToQuery($query, $sort);

        if ($filters !== null) {
            $query['where'] = $this->convertFiltersQueryParams($filters, $schema);
        }

        if ($fields !== null) {
            $query['select'] = $this->convertFieldsQueryParams($fields, $schema);
        }

        if ($populate !== null) {
            $query['populate'] = $this->convertPopulateQueryParams($populate, $schema);
        }

        $this->validatePaginationParams($page, $pageSize, $start, $limit);

        if ($page !== null) {
            $query['page'] = $this->convertPageQueryParams($page);
        }

        if ($pageSize !== null) {
            $query['pageSize'] = $this->convertPageSizeQueryParams($pageSize, $page);
        }

        if ($start !== null) {
            $query['offset'] = $this->convertStartQueryParams($start);
        }

        if ($limit !== null) {
            $query['limit'] = $this->convertLimitQueryParams($limit);
        }

        return [...$rest, ...$query];
    }

    private static function jsonStringify(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }
}
