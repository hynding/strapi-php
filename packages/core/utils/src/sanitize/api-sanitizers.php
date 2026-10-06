<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Async;
use Strapi\Utils\ContentApiConstants;
use Strapi\Utils\ContentApiRouteParams;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\PublicationFilter;
use Strapi\Utils\Sanitize\Visitors\RemoveRestrictedFields;
use Strapi\Utils\Sanitize\Visitors\RemoveRestrictedRelations;
use Strapi\Utils\Sanitize\Visitors\RemoveUnrecognizedFields;
use Strapi\Utils\SortQuery;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\TraverseEntity;

/**
 * The `{ input, output, query, filters, sort, fields, populate }` object `createAPISanitizers` returns.
 *
 * Options accepted by every method: `auth` (anything; when set, relations the auth may not `find` are
 * removed — see {@see \Strapi\Utils\AuthScope}), `strictParams` (drop unknown keys) and `route`
 * (a route-like value, see {@see ContentApiRouteParams}).
 *
 * @phpstan-type Model Schema|array<string, mixed>
 * @phpstan-type Options array{auth?: mixed, strictParams?: bool, route?: array<string, mixed>|object|null}
 */
final class ApiSanitizers
{
    /** @var \Closure(string): (Schema|array<string, mixed>|null) */
    private readonly \Closure $getModel;

    /**
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     * @param array{input?: list<callable>, output?: list<callable>} $sanitizers registered sanitizers: `callable(Model $schema): callable(mixed): mixed`
     */
    public function __construct(callable $getModel, private readonly array $sanitizers = [])
    {
        $this->getModel = $getModel(...);
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function input(mixed $data, Schema|array|null $schema, array $options = []): mixed
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizeInput');
        }
        $auth = $options['auth'] ?? null;
        $strictParams = $options['strictParams'] ?? false;
        $route = $options['route'] ?? null;

        if (is_array($data) && array_is_list($data) && $data !== []) {
            return array_map(fn (mixed $entry): mixed => $this->input($entry, $schema, $options), $data);
        }

        $allowedExtraRootKeys = ContentApiRouteParams::getExtraRootKeysFromRouteBody($route);
        $nonWritableAttributes = ContentTypes::getNonWritableAttributes($schema);
        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];

        $transforms = [
            // Remove first level ID in inputs
            static fn (mixed $value): mixed => is_array($value) ? Objects::omit($value, [ContentTypes::ID_ATTRIBUTE]) : $value,
            static fn (mixed $value): mixed => is_array($value) ? Objects::omit($value, [ContentTypes::DOC_ID_ATTRIBUTE]) : $value,
            // Remove non-writable attributes
            TraverseEntity::create(new RemoveRestrictedFields($nonWritableAttributes), $ctx),
        ];

        if ($strictParams) {
            $transforms[] = TraverseEntity::create(new RemoveUnrecognizedFields(), [...$ctx, 'allowedExtraRootKeys' => $allowedExtraRootKeys]);
        }

        if ($auth) {
            $transforms[] = TraverseEntity::create(new RemoveRestrictedRelations($auth), $ctx);
        }

        foreach ($this->sanitizers['input'] ?? [] as $sanitizer) {
            $transforms[] = $sanitizer($schema);
        }

        // For each extra root key from the route's body schema present in data, run its validator;
        // if parsing fails, the key is removed from the output. "data" is skipped (already handled above).
        $transforms[] = static function (mixed $data) use ($route): mixed {
            if (!is_array($data)) {
                return $data;
            }
            $shape = ContentApiRouteParams::routeBodyShape($route);
            if ($shape === null) {
                return $data;
            }
            foreach ($shape as $key => $validator) {
                if ($key === 'data' || !array_key_exists($key, $data)) {
                    continue;
                }
                [$ok, $result] = ContentApiRouteParams::runValidator($validator, $data[$key]);
                if ($ok) {
                    $data[$key] = $result;
                } else {
                    unset($data[$key]);
                }
            }

            return $data;
        };

        return Async::pipe(...$transforms)($data);
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function output(mixed $data, Schema|array|null $schema, array $options = []): mixed
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizeOutput');
        }
        $auth = $options['auth'] ?? null;

        if (is_array($data) && array_is_list($data) && $data !== []) {
            return array_map(fn (mixed $entry): mixed => $this->output($entry, $schema, ['auth' => $auth]), $data);
        }

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];

        $transforms = [
            static fn (mixed $entity): mixed => Sanitizers::defaultSanitizeOutput($ctx, $entity),
        ];

        if ($auth) {
            $transforms[] = TraverseEntity::create(new RemoveRestrictedRelations($auth), $ctx);
        }

        foreach ($this->sanitizers['output'] ?? [] as $sanitizer) {
            $transforms[] = $sanitizer($schema);
        }

        return Async::pipe(...$transforms)($data);
    }

    /**
     * @param array<string, mixed> $query
     * @param Model|null $schema
     * @param Options $options
     * @return array<string, mixed>
     */
    public function query(array $query, Schema|array|null $schema, array $options = []): array
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizeQuery');
        }
        $auth = $options['auth'] ?? null;
        $strictParams = $options['strictParams'] ?? false;
        $route = $options['route'] ?? null;

        $sanitizedQuery = $query;

        if (array_key_exists('publicationFilter', $sanitizedQuery)) {
            PublicationFilter::validatePublicationFilterQueryParam($sanitizedQuery['publicationFilter']);
        }

        $filters = $query['filters'] ?? null;
        $sort = $query['sort'] ?? null;
        $fields = $query['fields'] ?? null;
        $populate = $query['populate'] ?? null;

        if ($filters) {
            $sanitizedQuery['filters'] = $this->filters($filters, $schema, ['auth' => $auth]);
        }

        if (SortQuery::hasSort($sort)) {
            $sanitizedQuery['sort'] = $this->sort($sort, $schema, ['auth' => $auth]);
        } elseif (array_key_exists('sort', $sanitizedQuery)) {
            unset($sanitizedQuery['sort']);
        }

        if ($fields) {
            $sanitizedQuery['fields'] = $this->fields($fields, $schema);
        }

        if ($populate) {
            $sanitizedQuery['populate'] = $this->populate($populate, $schema, ['auth' => $auth]);
        }

        $extraQueryKeys = ContentApiRouteParams::getExtraQueryKeysFromRoute($route);
        $routeQuerySchema = ContentApiRouteParams::routeQuery($route);
        if ($routeQuerySchema !== null) {
            foreach ($extraQueryKeys as $key) {
                if (array_key_exists($key, $query)) {
                    [$ok, $result] = ContentApiRouteParams::runValidator($routeQuerySchema[$key] ?? null, $query[$key]);
                    if ($ok) {
                        $sanitizedQuery[$key] = $result;
                    } else {
                        unset($sanitizedQuery[$key]);
                    }
                }
            }
        }

        if ($strictParams) {
            return Objects::pick($sanitizedQuery, [...ContentApiConstants::ALLOWED_QUERY_PARAM_KEYS, ...$extraQueryKeys]);
        }

        return $sanitizedQuery;
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function filters(mixed $filters, Schema|array|null $schema, array $options = []): mixed
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizeFilters');
        }
        $auth = $options['auth'] ?? null;

        if (is_array($filters) && array_is_list($filters) && $filters !== []) {
            return array_map(fn (mixed $filter): mixed => $this->filters($filter, $schema, ['auth' => $auth]), $filters);
        }

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $transforms = [static fn (mixed $f): mixed => Sanitizers::defaultSanitizeFilters($ctx, $f)];

        if ($auth) {
            $transforms[] = QueryFilters::create(new RemoveRestrictedRelations($auth), $ctx);
        }

        return Async::pipe(...$transforms)($filters);
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function sort(mixed $sort, Schema|array|null $schema, array $options = []): mixed
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizeSort');
        }
        $auth = $options['auth'] ?? null;

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $transforms = [static fn (mixed $s): mixed => Sanitizers::defaultSanitizeSort($ctx, $s)];

        if ($auth) {
            $transforms[] = QuerySort::create(new RemoveRestrictedRelations($auth), $ctx);
        }

        return Async::pipe(...$transforms)($sort);
    }

    /** @param Model|null $schema */
    public function fields(mixed $fields, Schema|array|null $schema): mixed
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizeFields');
        }

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];

        return Sanitizers::defaultSanitizeFields($ctx, $fields);
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function populate(mixed $populate, Schema|array|null $schema, array $options = []): mixed
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizePopulate');
        }
        $auth = $options['auth'] ?? null;

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $transforms = [static fn (mixed $p): mixed => Sanitizers::defaultSanitizePopulate($ctx, $p)];

        if ($auth) {
            $transforms[] = QueryPopulate::create(new RemoveRestrictedRelations($auth), $ctx);
        }

        return Async::pipe(...$transforms)($populate);
    }
}
