<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Async;
use Strapi\Utils\ContentApiConstants;
use Strapi\Utils\ContentApiRouteParams;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\PublicationFilter;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Validate\Visitors\ThrowRestrictedFields;
use Strapi\Utils\Validate\Visitors\ThrowRestrictedRelations;
use Strapi\Utils\Validate\Visitors\ThrowUnrecognizedFields;

/**
 * The `{ input, query, filters, sort, fields, populate }` object `createAPIValidators` returns.
 * Every method throws a {@see ValidationError} (with `details.source` / `details.param` set) and returns nothing.
 *
 * @phpstan-type Model Schema|array<string, mixed>
 * @phpstan-type Options array{auth?: mixed, strictParams?: bool, route?: array<string, mixed>|object|null}
 */
final class ApiValidators
{
    /** @var \Closure(string): (Schema|array<string, mixed>|null) */
    private readonly \Closure $getModel;

    /**
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     * @param array{input?: list<callable>} $validators registered validators: `callable(Model $schema): callable(mixed): mixed`
     */
    public function __construct(callable $getModel, private readonly array $validators = [])
    {
        $this->getModel = $getModel(...);
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function input(mixed $data, Schema|array|null $schema, array $options = []): void
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in validateInput');
        }
        $auth = $options['auth'] ?? null;
        $route = $options['route'] ?? null;

        if (is_array($data) && array_is_list($data) && $data !== []) {
            foreach ($data as $entry) {
                $this->input($entry, $schema, $options);
            }

            return;
        }

        $allowedExtraRootKeys = ContentApiRouteParams::getExtraRootKeysFromRouteBody($route);
        $nonWritableAttributes = ContentTypes::getNonWritableAttributes($schema);
        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];

        $transforms = [
            static function (mixed $data): mixed {
                if (is_array($data)) {
                    if (array_key_exists(ContentTypes::ID_ATTRIBUTE, $data)) {
                        Utils::throwInvalidKey(['key' => ContentTypes::ID_ATTRIBUTE]);
                    }
                    if (array_key_exists(ContentTypes::DOC_ID_ATTRIBUTE, $data)) {
                        Utils::throwInvalidKey(['key' => ContentTypes::DOC_ID_ATTRIBUTE]);
                    }
                }

                return $data;
            },
            // non-writable attributes
            TraverseEntity::create(new ThrowRestrictedFields($nonWritableAttributes), $ctx),
            // unrecognized attributes (allowedExtraRootKeys = registered input param keys)
            TraverseEntity::create(new ThrowUnrecognizedFields(), [...$ctx, 'allowedExtraRootKeys' => $allowedExtraRootKeys]),
        ];

        if ($auth) {
            $transforms[] = TraverseEntity::create(new ThrowRestrictedRelations($auth), $ctx);
        }

        foreach ($this->validators['input'] ?? [] as $validator) {
            $transforms[] = $validator($schema);
        }

        try {
            Async::pipe(...$transforms)($data);

            // Validate extra root keys from the route's body schema (throw on failure); "data" is skipped.
            if (is_array($data)) {
                $shape = ContentApiRouteParams::routeBodyShape($route);
                foreach ($shape ?? [] as $key => $validator) {
                    if ($key === 'data' || !array_key_exists($key, $data)) {
                        continue;
                    }
                    [$ok, $result] = ContentApiRouteParams::runValidator($validator, $data[$key]);
                    if (!$ok) {
                        throw new ValidationError(is_string($result) && $result !== '' ? $result : 'Validation failed', ['key' => $key, 'path' => null, 'source' => 'body', 'param' => $key]);
                    }
                }
            }
        } catch (ValidationError $e) {
            $e->details['source'] = 'body';
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param Model|null $schema
     * @param Options $options
     */
    public function query(array $query, Schema|array|null $schema, array $options = []): void
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in validateQuery');
        }
        $auth = $options['auth'] ?? null;
        $strictParams = $options['strictParams'] ?? false;
        $route = $options['route'] ?? null;

        if (array_key_exists('publicationFilter', $query)) {
            PublicationFilter::validatePublicationFilterQueryParam($query['publicationFilter']);
        }

        if ($strictParams) {
            $extraQueryKeys = ContentApiRouteParams::getExtraQueryKeysFromRoute($route);
            $allowedKeys = [...ContentApiConstants::ALLOWED_QUERY_PARAM_KEYS, ...$extraQueryKeys];
            foreach (array_keys($query) as $key) {
                if (!in_array((string) $key, $allowedKeys, true)) {
                    try {
                        Utils::throwInvalidKey(['key' => (string) $key, 'path' => null]);
                    } catch (ValidationError $e) {
                        $e->details['source'] = 'query';
                        $e->details['param'] = (string) $key;
                        throw $e;
                    }
                }
            }
            $routeQuerySchema = ContentApiRouteParams::routeQuery($route);
            if ($routeQuerySchema !== null) {
                foreach ($extraQueryKeys as $key) {
                    if (array_key_exists($key, $query)) {
                        [$ok, $result] = ContentApiRouteParams::runValidator($routeQuerySchema[$key] ?? null, $query[$key]);
                        if (!$ok) {
                            throw new ValidationError(is_string($result) && $result !== '' ? $result : 'Invalid query param', ['key' => $key, 'path' => null, 'source' => 'query', 'param' => $key]);
                        }
                    }
                }
            }
        }

        $filters = $query['filters'] ?? null;
        $sort = $query['sort'] ?? null;
        $fields = $query['fields'] ?? null;
        $populate = $query['populate'] ?? null;

        if ($filters) {
            $this->filters($filters, $schema, ['auth' => $auth]);
        }
        if ($sort) {
            $this->sort($sort, $schema, ['auth' => $auth]);
        }
        if ($fields) {
            $this->fields($fields, $schema);
        }
        // a wildcard is always valid; its conversion is handled later
        if ($populate && $populate !== '*') {
            $this->populate($populate, $schema);
        }
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function filters(mixed $filters, Schema|array|null $schema, array $options = []): void
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in validateFilters');
        }
        $auth = $options['auth'] ?? null;

        if (is_array($filters) && array_is_list($filters) && $filters !== []) {
            foreach ($filters as $filter) {
                $this->filters($filter, $schema, ['auth' => $auth]);
            }

            return;
        }

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $transforms = [static fn (mixed $f): mixed => Validators::defaultValidateFilters($ctx, $f)];

        if ($auth) {
            $transforms[] = QueryFilters::create(new ThrowRestrictedRelations($auth), $ctx);
        }

        $this->run($transforms, $filters, 'filters');
    }

    /**
     * @param Model|null $schema
     * @param Options $options
     */
    public function sort(mixed $sort, Schema|array|null $schema, array $options = []): void
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in validateSort');
        }
        $auth = $options['auth'] ?? null;

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $transforms = [static fn (mixed $s): mixed => Validators::defaultValidateSort($ctx, $s)];

        if ($auth) {
            $transforms[] = QuerySort::create(new ThrowRestrictedRelations($auth), $ctx);
        }

        $this->run($transforms, $sort, 'sort');
    }

    /** @param Model|null $schema */
    public function fields(mixed $fields, Schema|array|null $schema): void
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in validateFields');
        }

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $this->run([static fn (mixed $f): mixed => Validators::defaultValidateFields($ctx, $f)], $fields, 'fields');
    }

    /** @param Model|null $schema */
    public function populate(mixed $populate, Schema|array|null $schema): void
    {
        if ($schema === null) {
            throw new \LogicException('Missing schema in sanitizePopulate');
        }

        $ctx = ['schema' => $schema, 'getModel' => $this->getModel];
        $this->run([static fn (mixed $p): mixed => Validators::defaultValidatePopulate($ctx, $p)], $populate, 'populate');
    }

    /** @param list<callable> $transforms */
    private function run(array $transforms, mixed $value, string $param): void
    {
        try {
            Async::pipe(...$transforms)($value);
        } catch (ValidationError $e) {
            $e->details['source'] = 'query';
            $e->details['param'] = $param;
            throw $e;
        }
    }
}
