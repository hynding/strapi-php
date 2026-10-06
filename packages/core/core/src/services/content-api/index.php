<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentApi;

use Strapi\Core\Services\ContentApi\Permissions\Permissions;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentApiConstants;
use Strapi\Utils\Sanitize\ApiSanitizers;
use Strapi\Utils\Sanitize\Sanitize;
use Strapi\Utils\Validate\ApiValidators;
use Strapi\Utils\Validate\Validate;

/**
 * Port of packages/core/core/src/services/content-api/index.ts: `strapi.contentAPI`
 * (permissions, sanitize, validate, routes map, extra query/input params).
 *
 * Zod schemas are replaced by validators: a `callable(mixed): mixed` returning the parsed value or
 * throwing, or a {@see \Strapi\Utils\ParamValidator}; `addQueryParams` also accepts a factory
 * `callable(): callable` to mirror the `(z) => schema` form.
 *
 * @phpstan-type ParamEntry array{schema: mixed, matchRoute?: callable(array<string, mixed>): bool}
 */
final class ContentApi
{
    public readonly Permissions $permissions;

    private ?ApiSanitizers $sanitizer = null;

    private ?ApiValidators $validator = null;

    /** @var list<array{param: string, schema: mixed, matchRoute: ?callable}> */
    private array $extraQueryParams = [];

    /** @var list<array{param: string, schema: mixed, matchRoute: ?callable}> */
    private array $extraInputParams = [];

    public function __construct(private readonly Strapi $strapi)
    {
        $this->permissions = Permissions::instantiatePermissionsUtilities($strapi);

    }

    /**
     * Upstream reads the sanitizer registry through a getter on each call; here the instance is
     * built on first use (after `register()`, when plugins have added their sanitizers) and
     * refreshed with {@see self::refresh()}.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'sanitize' => $this->sanitize(),
            'validate' => $this->validate(),
            default => throw new \LogicException("Unknown property {$name}"),
        };
    }

    public function sanitize(): ApiSanitizers
    {
        if ($this->sanitizer === null) {
            $this->sanitizer = Sanitize::createAPISanitizers([
                'getModel' => fn (string $uid) => $this->strapi->getModel($uid),
                'sanitizers' => [
                    'input' => $this->strapi->get('sanitizers')->get('content-api.input'),
                    'output' => $this->strapi->get('sanitizers')->get('content-api.output'),
                ],
            ]);
        }

        return $this->sanitizer;
    }

    public function validate(): ApiValidators
    {
        if ($this->validator === null) {
            $this->validator = Validate::createAPIValidators([
                'getModel' => fn (string $uid) => $this->strapi->getModel($uid),
                'validators' => ['input' => $this->strapi->get('validators')->get('content-api.input')],
            ]);
        }

        return $this->validator;
    }

    /** Drop the cached sanitizer/validator instances (call after registering new sanitizers). */
    public function refresh(): void
    {
        $this->sanitizer = null;
        $this->validator = null;
    }

    public static function createContentAPI(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private static function resolveSchema(mixed $schemaOrFactory): mixed
    {
        // a factory `fn () => validator` (upstream `(z) => z.string()`) - a Closure with no required params
        if ($schemaOrFactory instanceof \Closure) {
            $reflection = new \ReflectionFunction($schemaOrFactory);
            if ($reflection->getNumberOfRequiredParameters() === 0 && $reflection->getNumberOfParameters() === 0) {
                return $schemaOrFactory();
            }
        }

        return $schemaOrFactory;
    }

    /** @param array<string, ParamEntry> $options */
    public function addQueryParams(array $options): void
    {
        foreach ($options as $param => $rest) {
            $param = (string) $param;
            $schema = self::resolveSchema($rest['schema'] ?? null);

            if (in_array($param, ContentApiConstants::ALLOWED_QUERY_PARAM_KEYS, true)) {
                throw new \RuntimeException("contentAPI.addQueryParams: param \"{$param}\" is reserved by Strapi; use a different name");
            }
            foreach ($this->extraQueryParams as $existing) {
                if ($existing['param'] === $param) {
                    throw new \RuntimeException("contentAPI.addQueryParams: param \"{$param}\" has already been added");
                }
            }
            $this->extraQueryParams[] = ['param' => $param, 'schema' => $schema, 'matchRoute' => $rest['matchRoute'] ?? null];
        }
    }

    /** @param array<string, ParamEntry> $options */
    public function addInputParams(array $options): void
    {
        foreach ($options as $param => $rest) {
            $param = (string) $param;
            $schema = self::resolveSchema($rest['schema'] ?? null);

            if (in_array($param, ContentApiConstants::RESERVED_INPUT_PARAM_KEYS, true)) {
                throw new \RuntimeException("contentAPI.addInputParams: param \"{$param}\" is reserved by Strapi; use a different name");
            }
            foreach ($this->extraInputParams as $existing) {
                if ($existing['param'] === $param) {
                    throw new \RuntimeException("contentAPI.addInputParams: param \"{$param}\" has already been added");
                }
            }
            $this->extraInputParams[] = ['param' => $param, 'schema' => $schema, 'matchRoute' => $rest['matchRoute'] ?? null];
        }
    }

    /**
     * Merge all registered extra params into the given routes (mutates in place). Called at route
     * registration. Throws if a param key already exists.
     *
     * @param list<array<string, mixed>> $routes
     */
    public function applyExtraParamsToRoutes(array &$routes): void
    {
        foreach ($routes as &$route) {
            foreach ($this->extraQueryParams as ['param' => $param, 'schema' => $schema, 'matchRoute' => $matchRoute]) {
                if ($matchRoute !== null && !$matchRoute($route)) {
                    continue;
                }
                $query = $route['request']['query'] ?? [];
                if (array_key_exists($param, $query)) {
                    throw new \RuntimeException("contentAPI.addQueryParams: param \"{$param}\" already exists on route {$route['method']} {$route['path']}");
                }
                $query[$param] = $schema;
                $route['request'] = [...($route['request'] ?? []), 'query' => $query];
            }
            foreach ($this->extraInputParams as ['param' => $param, 'schema' => $schema, 'matchRoute' => $matchRoute]) {
                if ($matchRoute !== null && !$matchRoute($route)) {
                    continue;
                }
                $body = $route['request']['body'] ?? [];
                $shape = $body['application/json']['shape'] ?? [];
                if (array_key_exists($param, $shape)) {
                    throw new \RuntimeException("contentAPI.addInputParams: param \"{$param}\" already exists on route {$route['method']} {$route['path']}");
                }
                $shape[$param] = $schema;
                $body['application/json'] = ['shape' => $shape];
                $route['request'] = [...($route['request'] ?? []), 'body' => $body];
            }
        }
        unset($route);
    }

    /**
     * Every content-api route grouped by API / plugin, with the REST prefix applied and the
     * request/response validators stripped (serializable).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getRoutesMap(): array
    {
        $routesMap = [];
        $apiPrefix = (string) $this->strapi->config()->get('api.rest.prefix');
        $filterContentAPI = static fn (array $route): bool => ($route['info']['type'] ?? null) === 'content-api';

        foreach ($this->strapi->apis() as $apiName => $api) {
            $routes = array_values(array_filter(self::flattenRoutes($api->routes()), $filterContentAPI));
            if ($routes === []) {
                continue;
            }
            $routesMap["api::{$apiName}"] = array_map(
                static fn (array $route): array => self::sanitizeRoute([...$route, 'path' => $apiPrefix . $route['path']]),
                $routes,
            );
        }

        foreach ($this->strapi->plugins() as $pluginName => $plugin) {
            $pluginRoutes = array_map(static function (array $route) use ($pluginName): array {
                $prefix = $route['config']['prefix'] ?? null;
                $path = $prefix !== null ? $prefix . $route['path'] : "/{$pluginName}" . $route['path'];

                return [...$route, 'path' => $path];
            }, self::flattenRoutes($plugin->routes()));

            $routes = array_values(array_filter($pluginRoutes, $filterContentAPI));
            if ($routes === []) {
                continue;
            }
            $routesMap["plugin::{$pluginName}"] = array_map(
                static fn (array $route): array => self::sanitizeRoute([...$route, 'path' => $apiPrefix . $route['path']]),
                $routes,
            );
        }

        return $routesMap;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private static function flattenRoutes(array $routes): array
    {
        if (array_is_list($routes)) {
            return $routes;
        }
        $out = [];
        foreach ($routes as $router) {
            if (is_array($router) && isset($router['routes']) && is_array($router['routes'])) {
                foreach ($router['routes'] as $route) {
                    $out[] = $route;
                }
            } elseif (is_array($router) && isset($router['method'])) {
                $out[] = $router;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $route @return array<string, mixed> */
    private static function sanitizeRoute(array $route): array
    {
        unset($route['request'], $route['response']);

        return $route;
    }
}
