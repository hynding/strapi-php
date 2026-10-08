<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/services/utils/naming.ts: GraphQL type names for content types, components,
 * attributes and the generated queries / mutations.
 */
final class Naming
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** lodash `upperFirst(camelCase(value))` */
    private static function pascal(string $value): string
    {
        return Strings::upperFirst(Strings::camelCase($value));
    }

    /** pluralize's `singular()`, used when a schema has no `info.singularName` */
    private static function singular(string $word): string
    {
        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }
        if (preg_match('/(ss|sh|ch|x|z)es$/', $word) === 1) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Build a type name for a enum based on a content type & an attribute name
     */
    public function getEnumName(Schema $contentType, string $attributeName): string
    {
        $attributes = $contentType->attributes;
        $enumName = $attributes[$attributeName]['enumName'] ?? null;
        $modelType = $contentType->modelType;

        $typeName = $modelType === 'component' ? $this->getComponentName($contentType) : $this->getTypeName($contentType);

        $defaultEnumName = 'ENUM_' . strtoupper($typeName) . '_' . strtoupper($attributeName);

        return is_string($enumName) && $enumName !== '' ? $enumName : $defaultEnumName;
    }

    /**
     * Build the base type name for a given content type
     *
     * @param array{plurality?: 'singular'|'plural'} $options
     */
    public function getTypeName(Schema $contentType, array $options = []): string
    {
        $plurality = $options['plurality'] ?? 'singular';
        $plugin = $contentType->plugin;
        $modelName = $contentType->modelName;
        $name = $plurality === 'singular' ? ($contentType->info['singularName'] ?? null) : ($contentType->info['pluralName'] ?? null);

        $transformedPlugin = $plugin !== null && $plugin !== '' ? self::pascal($plugin) : '';
        $transformedModelName = self::pascal(is_string($name) && $name !== '' ? $name : self::singular($modelName));

        return "{$transformedPlugin}{$transformedModelName}";
    }

    /**
     * Build the entity's type name for a given content type
     */
    public function getEntityName(Schema $contentType): string
    {
        return $this->getTypeName($contentType) . 'Entity';
    }

    /**
     * Build the entity meta type name for a given content type
     */
    public function getEntityMetaName(Schema $contentType): string
    {
        return $this->getEntityName($contentType) . 'Meta';
    }

    /**
     * Build the entity response's type name for a given content type
     */
    public function getEntityResponseName(Schema $contentType): string
    {
        return $this->getEntityName($contentType) . 'Response';
    }

    /**
     * Build the entity response collection's type name for a given content type
     */
    public function getEntityResponseCollectionName(Schema $contentType): string
    {
        return $this->getEntityName($contentType) . 'ResponseCollection';
    }

    /**
     * Build the relation response collection's type name for a given content type
     */
    public function getRelationResponseCollectionName(Schema $contentType): string
    {
        return $this->getTypeName($contentType) . 'RelationResponseCollection';
    }

    /**
     * Build a component type name based on its definition
     */
    public function getComponentName(Schema $contentType): string
    {
        return $contentType->globalId;
    }

    /**
     * Build a component type name based on a content type's attribute
     *
     * @param array<string, mixed> $attribute
     */
    public function getComponentNameFromAttribute(array $attribute): string
    {
        $component = $this->strapi->components()[(string) ($attribute['component'] ?? '')] ?? null;
        if ($component === null) {
            throw new ApplicationError('Unknown component ' . (string) ($attribute['component'] ?? ''));
        }

        return $component->globalId;
    }

    /**
     * Build a dynamic zone type name based on a content type and an attribute name
     */
    public function getDynamicZoneName(Schema $contentType, string $attributeName): string
    {
        $typeName = $this->getTypeName($contentType);
        $dzName = self::pascal($attributeName);
        $suffix = 'DynamicZone';

        return "{$typeName}{$dzName}{$suffix}";
    }

    /**
     * Build a dynamic zone input type name based on a content type and an attribute name
     */
    public function getDynamicZoneInputName(Schema $contentType, string $attributeName): string
    {
        $dzName = $this->getDynamicZoneName($contentType, $attributeName);

        return "{$dzName}Input";
    }

    /**
     * Build a component input type name based on a content type and an attribute name
     */
    public function getComponentInputName(Schema $contentType): string
    {
        $componentName = $this->getComponentName($contentType);

        return "{$componentName}Input";
    }

    /**
     * Build a content type input name based on a content type and an attribute name
     */
    public function getContentTypeInputName(Schema $contentType): string
    {
        $typeName = $this->getTypeName($contentType);

        return "{$typeName}Input";
    }

    /**
     * Build the queries type name for a given content type
     */
    public function getEntityQueriesTypeName(Schema $contentType): string
    {
        return $this->getEntityName($contentType) . 'Queries';
    }

    /**
     * Build the mutations type name for a given content type
     */
    public function getEntityMutationsTypeName(Schema $contentType): string
    {
        return $this->getEntityName($contentType) . 'Mutations';
    }

    /**
     * Build the filters type name for a given content type
     */
    public function getFiltersInputTypeName(Schema $contentType): string
    {
        $isComponent = $contentType->modelType === 'component';

        $baseName = $isComponent ? $this->getComponentName($contentType) : $this->getTypeName($contentType);

        return "{$baseName}FiltersInput";
    }

    /**
     * Build a filters type name for a given GraphQL scalar type
     */
    public function getScalarFilterInputTypeName(string $scalarType): string
    {
        return "{$scalarType}FilterInput";
    }

    /**
     * Build a type name for a given content type & polymorphic attribute
     */
    public function getMorphRelationTypeName(Schema $contentType, string $attributeName): string
    {
        $typeName = $this->getTypeName($contentType);
        $formattedAttr = self::pascal($attributeName);

        return "{$typeName}{$formattedAttr}Morph";
    }

    /**
     * Build a custom type name generator with different customization options
     *
     * @param array{prefix?: string, suffix?: string, firstLetterCase?: 'upper'|'lower', plurality?: string} $options
     * @return \Closure(Schema): string
     */
    public function buildCustomTypeNameGenerator(array $options = []): \Closure
    {
        // todo[v4]: use singularName & pluralName is available
        $prefix = $options['prefix'] ?? '';
        $suffix = $options['suffix'] ?? '';
        $plurality = $options['plurality'] ?? 'singular';
        $firstLetterCase = $options['firstLetterCase'] ?? 'upper';

        if (!in_array($plurality, ['plural', 'singular'], true)) {
            throw new ApplicationError("\"plurality\" param must be either \"plural\" or \"singular\", but got: \"{$plurality}\"");
        }

        return function (Schema $contentType) use ($prefix, $suffix, $plurality, $firstLetterCase): string {
            $name = $this->getTypeName($contentType, ['plurality' => $plurality]);
            $name = $firstLetterCase === 'upper' ? ucfirst($name) : lcfirst($name);

            return "{$prefix}{$name}{$suffix}";
        };
    }

    public function getFindQueryName(Schema $contentType): string
    {
        return $this->buildCustomTypeNameGenerator(['plurality' => 'plural', 'firstLetterCase' => 'lower'])($contentType);
    }

    public function getFindConnectionQueryName(Schema $contentType): string
    {
        return $this->getFindQueryName($contentType) . '_connection';
    }

    public function getFindOneQueryName(Schema $contentType): string
    {
        return $this->buildCustomTypeNameGenerator(['firstLetterCase' => 'lower'])($contentType);
    }

    public function getCreateMutationTypeName(Schema $contentType): string
    {
        return $this->buildCustomTypeNameGenerator(['prefix' => 'create', 'firstLetterCase' => 'upper'])($contentType);
    }

    public function getUpdateMutationTypeName(Schema $contentType): string
    {
        return $this->buildCustomTypeNameGenerator(['prefix' => 'update', 'firstLetterCase' => 'upper'])($contentType);
    }

    public function getDeleteMutationTypeName(Schema $contentType): string
    {
        return $this->buildCustomTypeNameGenerator(['prefix' => 'delete', 'firstLetterCase' => 'upper'])($contentType);
    }
}
