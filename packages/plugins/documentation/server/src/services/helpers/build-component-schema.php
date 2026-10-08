<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\CleanSchemaAttributes;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\LoopContentTypeNames;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\PascalCase;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\Routes;

/**
 * Port of server/src/services/helpers/build-component-schema.ts. Upstream reads the global
 * `strapi`; here it is passed explicitly.
 *
 * @phpstan-import-type Api from \Strapi\Plugin\Documentation\Types
 * @phpstan-import-type ApiInfo from \Strapi\Plugin\Documentation\Types
 */
final class BuildComponentSchema
{
    private const ATTRIBUTES_TO_OMIT = [
        'createdAt',
        'updatedAt',
        'publishedAt',
        'publishedBy',
        'updatedBy',
        'createdBy',
    ];

    /**
     * @param array<string, array<string, mixed>> $allAttributes
     *
     * @return list<string>
     */
    private static function getRequiredAttributes(array $allAttributes): array
    {
        $requiredAttributes = [];

        foreach ($allAttributes as $key => $attribute) {
            if (!empty($attribute['required'])) {
                $requiredAttributes[] = (string) $key;
            }
        }

        return $requiredAttributes;
    }

    /**
     * Get all open api schema objects for a given content type.
     *
     * @param Strapi $strapi
     * @param ApiInfo $apiInfo
     *
     * @return array<string, mixed> Open API schemas
     */
    private static function getAllSchemasForContentType(object $strapi, array $apiInfo): array
    {
        $routeInfo = $apiInfo['routeInfo'];
        $attributes = $apiInfo['attributes'];
        $uniqueName = $apiInfo['uniqueName'];

        // Store response and request schemas in an object
        $strapiComponentSchemas = [];
        $schemas = [];
        $typeName = PascalCase::pascalCase($uniqueName);

        // adds a ComponentSchema to the Schemas so it can be used as Ref
        $didAddStrapiComponentsToSchemas = static function (string $schemaName, array $schema) use (&$strapiComponentSchemas): bool {
            // upstream: `!Object.keys(schema) || !Object.keys(schema.properties!)` is never true

            // Add the Strapi components to the schema
            $strapiComponentSchemas[$schemaName] = $schema;

            return true;
        };

        $routes = is_array($routeInfo['routes'] ?? null) ? $routeInfo['routes'] : [];

        // Get all the route methods
        $routeMethods = array_map(static fn (array $route): mixed => $route['method'] ?? null, $routes);

        $attributesForRequest = array_diff_key($attributes, array_flip(self::ATTRIBUTES_TO_OMIT));
        // Get a list of required attribute names
        $requiredRequestAttributes = self::getRequiredAttributes($attributesForRequest);
        // Build the request schemas when the route has POST or PUT methods
        if (in_array('POST', $routeMethods, true) || in_array('PUT', $routeMethods, true)) {
            // Build the request schema
            $requestProperties = CleanSchemaAttributes::cleanSchemaAttributes($attributesForRequest, [
                'strapi' => $strapi,
                'isRequest' => true,
                'didAddStrapiComponentsToSchemas' => $didAddStrapiComponentsToSchemas,
            ]);
            $schemas["{$typeName}Request"] = [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        ...(count($requiredRequestAttributes) > 0 ? ['required' => $requiredRequestAttributes] : []),
                        'type' => 'object',
                        'properties' => $requestProperties === [] ? new \stdClass() : $requestProperties,
                    ],
                ],
            ];
        }

        // Check for routes that need to return a list
        $hasListOfEntities = count(array_filter($routes, static fn (array $route): bool => Routes::hasFindMethod($route['handler'] ?? null)));

        if ($hasListOfEntities > 0) {
            // Build the list response schema
            $schemas["{$typeName}ListResponse"] = [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => "#/components/schemas/{$typeName}",
                        ],
                    ],
                    'meta' => [
                        'type' => 'object',
                        'properties' => [
                            'pagination' => [
                                'type' => 'object',
                                'properties' => [
                                    'page' => ['type' => 'integer'],
                                    'pageSize' => ['type' => 'integer', 'minimum' => 25],
                                    'pageCount' => ['type' => 'integer', 'maximum' => 1],
                                    'total' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $requiredAttributes = self::getRequiredAttributes($attributes);
        // Build the response schema
        $schemas[$typeName] = [
            'type' => 'object',
            ...(count($requiredAttributes) > 0 ? ['required' => $requiredAttributes] : []),
            'properties' => [
                'id' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]],
                'documentId' => ['type' => 'string'],
                ...CleanSchemaAttributes::cleanSchemaAttributes($attributes, [
                    'strapi' => $strapi,
                    'didAddStrapiComponentsToSchemas' => $didAddStrapiComponentsToSchemas,
                ]),
            ],
        ];

        $schemas["{$typeName}Response"] = [
            'type' => 'object',
            'properties' => [
                'data' => [
                    '$ref' => "#/components/schemas/{$typeName}",
                ],
                'meta' => ['type' => 'object'],
            ],
        ];

        return [...$schemas, ...$strapiComponentSchemas];
    }

    /**
     * @param Strapi $strapi
     * @param Api $api
     *
     * @return array<string, mixed>
     */
    public static function buildComponentSchema(object $strapi, array $api): array
    {
        // A reusable loop for building paths and component schemas
        // Uses the api param to build a new set of params for each content type
        // Passes these new params to the function provided
        return LoopContentTypeNames::loopContentTypeNames(
            $strapi,
            $api,
            static fn (array $apiInfo): array => self::getAllSchemasForContentType($strapi, $apiInfo),
        );
    }
}
