<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers\Utils;

use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/helpers/utils/clean-schema-attributes.ts: converts the types found
 * on attributes to OpenAPI data types. Upstream reads the global `strapi`; here it is the
 * `strapi` option (defaulting to `Core::instance()`). `typeMap` is the shared `Map` of upstream
 * (an `\ArrayObject`), `{}` schemas are `\stdClass`.
 *
 * @phpstan-type Options array{strapi?: Strapi, typeMap?: \ArrayObject<string, bool>, isRequest?: bool, didAddStrapiComponentsToSchemas: callable(string, array<string, mixed>): bool}
 */
final class CleanSchemaAttributes
{
    /** Convert attribute component names to OpenAPI component names. */
    public static function convertComponentName(string $component, bool $isRef = false): string
    {
        $cleanComponentName = PascalCase::pascalCase($component) . 'Component';

        if ($isRef) {
            return "#/components/schemas/{$cleanComponentName}";
        }

        return $cleanComponentName;
    }

    /**
     * @param array<string, array<string, mixed>> $attributes
     * @param Options $options
     *
     * @return array<string, array<string, mixed>|\stdClass> attributes using OpenAPI acceptable data types
     */
    public static function cleanSchemaAttributes(array $attributes, array $options): array
    {
        /** @var Strapi $strapi */
        $strapi = $options['strapi'] ?? Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');
        $typeMap = $options['typeMap'] ?? new \ArrayObject();
        $isRequest = $options['isRequest'] ?? false;
        $didAddStrapiComponentsToSchemas = $options['didAddStrapiComponentsToSchemas'];

        $schemaAttributes = [];

        foreach ($attributes as $prop => $attribute) {
            $prop = (string) $prop;

            switch ($attribute['type'] ?? null) {
                case 'password':
                    if (!$isRequest) {
                        break;
                    }

                    $schemaAttributes[$prop] = ['type' => 'string', 'format' => 'password', 'example' => '*******'];
                    break;

                case 'email':
                    $schemaAttributes[$prop] = ['type' => 'string', 'format' => 'email'];
                    break;

                case 'string':
                case 'text':
                case 'richtext':
                    $schemaAttributes[$prop] = ['type' => 'string'];
                    break;

                case 'timestamp':
                    $schemaAttributes[$prop] = ['type' => 'string', 'format' => 'timestamp', 'example' => (int) floor(microtime(true) * 1000)];
                    break;

                case 'time':
                    $schemaAttributes[$prop] = ['type' => 'string', 'format' => 'time', 'example' => '12:54.000'];
                    break;

                case 'date':
                    $schemaAttributes[$prop] = ['type' => 'string', 'format' => 'date'];
                    break;

                case 'datetime':
                    $schemaAttributes[$prop] = ['type' => 'string', 'format' => 'date-time'];
                    break;

                case 'boolean':
                    $schemaAttributes[$prop] = ['type' => 'boolean'];
                    break;

                case 'enumeration':
                    $schemaAttributes[$prop] = ['type' => 'string', 'enum' => array_values(is_array($attribute['enum'] ?? null) ? $attribute['enum'] : [])];
                    break;

                case 'decimal':
                case 'float':
                    $schemaAttributes[$prop] = ['type' => 'number', 'format' => 'float'];
                    break;

                case 'integer':
                    $schemaAttributes[$prop] = ['type' => 'integer'];
                    break;

                case 'biginteger':
                    $schemaAttributes[$prop] = ['type' => 'string', 'pattern' => '^\d*$', 'example' => '123456789'];
                    break;

                case 'json':
                case 'blocks':
                    $schemaAttributes[$prop] = new \stdClass();
                    break;

                case 'uid':
                    $schemaAttributes[$prop] = ['type' => 'string'];
                    break;

                case 'component':
                    $component = (string) $attribute['component'];
                    $componentAttributes = self::componentAttributes($strapi, $component);
                    $rawComponentSchema = [
                        'type' => 'object',
                        'properties' => self::properties([
                            ...($isRequest ? [] : ['id' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]]]),
                            ...self::cleanSchemaAttributes($componentAttributes, [
                                'strapi' => $strapi,
                                'typeMap' => $typeMap,
                                'isRequest' => $isRequest,
                                'didAddStrapiComponentsToSchemas' => $didAddStrapiComponentsToSchemas,
                            ]),
                        ]),
                    ];

                    $refComponentSchema = [
                        '$ref' => self::convertComponentName($component, true),
                    ];

                    $componentExists = $didAddStrapiComponentsToSchemas(self::convertComponentName($component), $rawComponentSchema);

                    $finalComponentSchema = $componentExists ? $refComponentSchema : $rawComponentSchema;
                    if (!empty($attribute['repeatable'])) {
                        $schemaAttributes[$prop] = [
                            'type' => 'array',
                            'items' => $finalComponentSchema,
                        ];
                    } else {
                        $schemaAttributes[$prop] = $finalComponentSchema;
                    }
                    break;

                case 'dynamiczone':
                    $componentNames = array_values(array_map('strval', is_array($attribute['components'] ?? null) ? $attribute['components'] : []));
                    $components = array_map(static function (string $component) use ($strapi, $typeMap, $isRequest, $didAddStrapiComponentsToSchemas): array {
                        $componentAttributes = self::componentAttributes($strapi, $component);
                        $rawComponentSchema = [
                            'type' => 'object',
                            'properties' => self::properties([
                                ...($isRequest ? [] : ['id' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]]]),
                                '__component' => ['type' => 'string', 'enum' => [$component]],
                                ...self::cleanSchemaAttributes($componentAttributes, [
                                    'strapi' => $strapi,
                                    'typeMap' => $typeMap,
                                    'isRequest' => $isRequest,
                                    'didAddStrapiComponentsToSchemas' => $didAddStrapiComponentsToSchemas,
                                ]),
                            ]),
                        ];

                        $refComponentSchema = [
                            '$ref' => self::convertComponentName($component, true),
                        ];

                        $componentExists = $didAddStrapiComponentsToSchemas(self::convertComponentName($component), $rawComponentSchema);

                        return $componentExists ? $refComponentSchema : $rawComponentSchema;
                    }, $componentNames);

                    $discriminator = null;
                    $allRefs = true;
                    foreach ($components as $component) {
                        if (!array_key_exists('$ref', $component)) {
                            $allRefs = false;
                        }
                    }
                    if ($allRefs) {
                        $mapping = [];
                        foreach ($componentNames as $component) {
                            $mapping[$component] = self::convertComponentName($component, true);
                        }
                        $discriminator = [
                            'propertyName' => '__component',
                            'mapping' => $mapping === [] ? new \stdClass() : $mapping,
                        ];
                    }

                    $schemaAttributes[$prop] = [
                        'type' => 'array',
                        'items' => [
                            'anyOf' => $components,
                        ],
                        // `discriminator: undefined` is dropped by JSON.stringify
                        ...($discriminator !== null ? ['discriminator' => $discriminator] : []),
                    ];
                    break;

                case 'media':
                    $imageAttributes = $strapi->contentType('plugin::upload.file')->attributes;
                    $isListOfEntities = (bool) ($attribute['multiple'] ?? false);

                    if ($isRequest) {
                        $oneOfType = [
                            'oneOf' => [['type' => 'integer'], ['type' => 'string']],
                            'example' => 'string or id',
                        ];

                        $schemaAttributes[$prop] = $isListOfEntities
                            ? ['type' => 'array', 'items' => $oneOfType]
                            : $oneOfType;
                        break;
                    }

                    $schemaAttributes[$prop] = GetSchemaData::getSchemaData(
                        $isListOfEntities,
                        self::cleanSchemaAttributes($imageAttributes, ['strapi' => $strapi, 'typeMap' => $typeMap, 'didAddStrapiComponentsToSchemas' => $didAddStrapiComponentsToSchemas]),
                    );
                    break;

                case 'relation':
                    $isListOfEntities = str_contains((string) ($attribute['relation'] ?? ''), 'ToMany');

                    if ($isRequest) {
                        $oneOfType = [
                            'oneOf' => [['type' => 'integer'], ['type' => 'string']],
                            'example' => 'string or id',
                        ];

                        $schemaAttributes[$prop] = $isListOfEntities
                            ? ['type' => 'array', 'items' => $oneOfType]
                            : $oneOfType;
                        break;
                    }

                    $target = $attribute['target'] ?? null;
                    if (!is_string($target) || $target === '' || isset($typeMap[$target])) {
                        $schemaAttributes[$prop] = GetSchemaData::getSchemaData($isListOfEntities, []);

                        break;
                    }

                    $typeMap[$target] = true;
                    $targetAttributes = $strapi->contentType($target)->attributes;

                    $schemaAttributes[$prop] = GetSchemaData::getSchemaData(
                        $isListOfEntities,
                        self::cleanSchemaAttributes($targetAttributes, [
                            'strapi' => $strapi,
                            'typeMap' => $typeMap,
                            'isRequest' => $isRequest,
                            'didAddStrapiComponentsToSchemas' => $didAddStrapiComponentsToSchemas,
                        ]),
                    );

                    break;

                default:
                    // This is a catch all for any other types
                    throw new \RuntimeException('Invalid type ' . self::stringify($attribute['type'] ?? null) . ' while generating open api schema.');
            }
        }

        return $schemaAttributes;
    }

    /**
     * `strapi.components[uid].attributes`
     *
     * @param Strapi $strapi
     *
     * @return array<string, array<string, mixed>>
     */
    private static function componentAttributes(object $strapi, string $uid): array
    {
        $component = $strapi->components()[$uid] ?? null;
        if ($component === null) {
            // upstream: TypeError reading `.attributes` of undefined
            throw new \RuntimeException("Cannot read properties of undefined (reading 'attributes')");
        }

        return $component->attributes;
    }

    /**
     * An OpenAPI `properties` object (`{}` when empty).
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function properties(array $properties): array|\stdClass
    {
        return $properties === [] ? new \stdClass() : $properties;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
