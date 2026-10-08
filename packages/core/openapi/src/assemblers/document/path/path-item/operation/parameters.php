<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Core\CoreApi\Routes\Validation\SchemaRegistry;
use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Assemblers\Document\QueryParamStyles;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Debug;
use Strapi\Openapi\Utils\Zod;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/parameters.ts.
 *
 * A route validator that is not a Zod schema (the PHP port allows plain validators or `null`
 * placeholders in `request.params` / `request.query`) is documented with an empty schema (`{}`):
 * required for a path parameter, optional for a query parameter.
 */
final class OperationParametersAssembler implements Assembler\Operation
{
    public function assemble(Context $context, array $route): void
    {
        $debug = Debug::createDebugger('assembler:parameters');

        $debug('assembling parameters for %o %o...', $route['method'] ?? null, $route['path'] ?? null);

        $schemaStore = $context->strapi->contentAPISchemaRegistry();
        $extractedComponentSchemas = $context->registries->extractedComponentSchemas;
        $pathParameters = $this->getPathParameters($route, $schemaStore, $extractedComponentSchemas);
        $debug('found %o path parameters', count($pathParameters));

        $queryParameters = $this->getQueryParameters($route, $schemaStore, $extractedComponentSchemas);
        $debug('found %o query parameters', count($queryParameters));

        $parameters = [...$pathParameters, ...$queryParameters];
        $debug('assembled %o parameters for %o %o', count($parameters), $route['method'] ?? null, $route['path'] ?? null);

        $context->output->data['parameters'] = $parameters;
    }

    /**
     * @param array<string, mixed> $route
     * @param \ArrayObject<string, array<string, mixed>|\stdClass> $extractedComponentSchemas
     *
     * @return list<array<string, mixed>>
     */
    private function getPathParameters(array $route, SchemaRegistry $schemaStore, \ArrayObject $extractedComponentSchemas): array
    {
        $params = is_array($route['request'] ?? null) ? ($route['request']['params'] ?? null) : null;

        // TODO: Allow auto inference (from path) if enabled through configuration
        if (!is_array($params)) {
            return [];
        }

        $pathParams = [];

        foreach ($params as $name => $zodSchema) {
            if ($zodSchema instanceof ZodType) {
                $required = !$zodSchema->isOptional();
                $schema = Zod::zodToOpenAPI($zodSchema, $schemaStore, ['extractedComponentSchemas' => $extractedComponentSchemas]);
            } else {
                $required = true;
                $schema = new \stdClass();
            }

            $pathParams[] = ['name' => (string) $name, 'in' => 'path', 'required' => $required, 'schema' => $schema];
        }

        return $pathParams;
    }

    /**
     * @param array<string, mixed> $route
     * @param \ArrayObject<string, array<string, mixed>|\stdClass> $extractedComponentSchemas
     *
     * @return list<array<string, mixed>>
     */
    private function getQueryParameters(array $route, SchemaRegistry $schemaStore, \ArrayObject $extractedComponentSchemas): array
    {
        $query = is_array($route['request'] ?? null) ? ($route['request']['query'] ?? null) : null;

        if (!is_array($query)) {
            return [];
        }

        $queryParams = [];

        foreach ($query as $name => $zodSchema) {
            $name = (string) $name;

            if ($zodSchema instanceof ZodType) {
                $required = !$zodSchema->isOptional();
                $schema = Zod::zodToOpenAPI($zodSchema, $schemaStore, ['extractedComponentSchemas' => $extractedComponentSchemas]);
            } else {
                $required = false;
                $schema = new \stdClass();
            }

            $resolvedSchema = $this->resolveSchema($schema);

            if (is_array($resolvedSchema) && QueryParamStyles::hasExpandableObjectProperties($resolvedSchema)) {
                $properties = is_array($resolvedSchema['properties'] ?? null) ? $resolvedSchema['properties'] : [];
                $requiredProperties = is_array($resolvedSchema['required'] ?? null) ? $resolvedSchema['required'] : null;

                foreach ($properties as $propName => $propSchema) {
                    $isRequired = $requiredProperties !== null && in_array((string) $propName, $requiredProperties, true);

                    $queryParams[] = [
                        'name' => "{$name}[{$propName}]",
                        'in' => 'query',
                        'required' => $isRequired,
                        'schema' => $propSchema,
                        'x-strapi-serialize' => 'querystring',
                    ];
                }
            } elseif (QueryParamStyles::shouldUseDeepObjectStyle($name, $resolvedSchema)) {
                $queryParams[] = [
                    'name' => $name,
                    'in' => 'query',
                    'required' => $required,
                    'schema' => $resolvedSchema,
                    'style' => 'deepObject',
                    'explode' => true,
                    'x-strapi-serialize' => 'querystring',
                ];
            } else {
                $queryParams[] = [
                    'name' => $name,
                    'in' => 'query',
                    'required' => $required,
                    'schema' => $schema,
                    'x-strapi-serialize' => 'querystring',
                ];
            }
        }

        return $queryParams;
    }

    /** JS truthiness of a schema's `properties` (`{}` is truthy). */
    private static function hasProperties(mixed $schema): bool
    {
        return is_array($schema) && isset($schema['properties']) && (is_array($schema['properties']) || $schema['properties'] instanceof \stdClass);
    }

    /**
     * Resolve composite OpenAPI schemas (allOf/anyOf/oneOf) into a flat object shape
     * so nested Strapi query params can be emitted as bracket notation (e.g. pagination[page]).
     *
     * @param array<string, mixed>|\stdClass $schema
     *
     * @return array<string, mixed>|\stdClass
     */
    private function resolveSchema(array|\stdClass $schema): array|\stdClass
    {
        if (!is_array($schema)) {
            return $schema;
        }

        if (($schema['type'] ?? null) === 'object' && self::hasProperties($schema)) {
            return $schema;
        }

        if (isset($schema['allOf']) && is_array($schema['allOf']) && array_is_list($schema['allOf'])) {
            $mergedSchema = [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ];

            foreach ($schema['allOf'] as $subSchema) {
                if ($subSchema instanceof \stdClass) {
                    continue; // `{}`: no properties
                }
                if (!is_array($subSchema) || array_key_exists('$ref', $subSchema)) {
                    continue;
                }
                $hasAnyOfOneOf = isset($subSchema['anyOf']) || isset($subSchema['oneOf']);
                $resolved = $this->resolveSchema($subSchema);

                if (is_array($resolved) && ($resolved['type'] ?? null) === 'object' && self::hasProperties($resolved)) {
                    $resolvedProperties = is_array($resolved['properties']) ? $resolved['properties'] : [];
                    foreach ($resolvedProperties as $key => $value) {
                        $mergedSchema['properties'][$key] = $value;
                    }
                    if (isset($resolved['required']) && is_array($resolved['required']) && !$hasAnyOfOneOf) {
                        $mergedSchema['required'] = [...$mergedSchema['required'], ...$resolved['required']];
                    }
                }
            }

            return QueryParamStyles::hasExpandableObjectProperties($mergedSchema) ? $mergedSchema : $schema;
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf']) && array_is_list($schema['anyOf'])) {
            return $this->extractCommonProperties($schema['anyOf'], false);
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf']) && array_is_list($schema['oneOf'])) {
            return $this->extractCommonProperties($schema['oneOf'], false);
        }

        return $schema;
    }

    /**
     * @param list<mixed> $schemas
     *
     * @return array<string, mixed>|\stdClass
     */
    private function extractCommonProperties(array $schemas, bool $preserveRequired = true): array|\stdClass
    {
        $first = $schemas[0] ?? new \stdClass();
        $first = is_array($first) || $first instanceof \stdClass ? $first : new \stdClass();

        $objectSchemas = [];
        foreach ($schemas as $schema) {
            if (!is_array($schema) && !$schema instanceof \stdClass) {
                continue;
            }
            if (is_array($schema) && array_key_exists('$ref', $schema)) {
                continue;
            }
            $resolved = $this->resolveSchema($schema);
            if (is_array($resolved) && ($resolved['type'] ?? null) === 'object' && self::hasProperties($resolved)) {
                $objectSchemas[] = $resolved;
            }
        }

        if ($objectSchemas === []) {
            return $first;
        }

        $allProperties = [];
        $allRequired = [];

        foreach ($objectSchemas as $objSchema) {
            foreach (is_array($objSchema['properties']) ? $objSchema['properties'] : [] as $key => $value) {
                $allProperties[$key] = $value;
            }
            if ($preserveRequired && isset($objSchema['required']) && is_array($objSchema['required'])) {
                array_push($allRequired, ...$objSchema['required']);
            }
        }

        if (!$preserveRequired) {
            $allRequired = [];
        }

        if ($allProperties === []) {
            return $first;
        }

        return [
            'type' => 'object',
            'properties' => $allProperties,
            'required' => $allRequired,
        ];
    }
}
