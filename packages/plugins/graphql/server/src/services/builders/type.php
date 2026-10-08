<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Utils\Utils as GraphqlUtils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/services/builders/type.ts
 *
 * @phpstan-type TypeBuildersOptions array{builder: OutputDefinitionBlock, attributeName: string, attribute: array<string, mixed>, contentType: Schema}
 */
final class Type
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function utils(): GraphqlUtils
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof GraphqlUtils);

        return $utils;
    }

    private function extension(): Extension
    {
        $extension = $this->strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        return $extension;
    }

    private function builders(): Builders
    {
        $builders = $this->strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);

        return $builders;
    }

    private function contentApiBuilders(): BuildersInstance
    {
        $builders = $this->builders()->get('content-api');
        if ($builders === null) {
            throw new \RuntimeException('The content-api builders are not initialized');
        }

        return $builders;
    }

    /**
     * Add a scalar attribute to the type definition
     *
     * The attribute is added based on a simple association between a Strapi
     * type and a GraphQL type (the map is defined in `strapiTypeToGraphQLScalar`)
     *
     * @param TypeBuildersOptions $options
     */
    private function addScalarAttribute(array $options): void
    {
        ['builder' => $builder, 'attributeName' => $attributeName, 'attribute' => $attribute] = $options;

        $gqlType = $this->utils()->mappers->strapiScalarToGraphQLScalar($attribute['type'] ?? null);

        $builder->field($attributeName, ['type' => $gqlType]);
    }

    /**
     * Add a component attribute to the type definition
     *
     * The attribute is added by fetching the component's type
     * name and using it as the attribute's type
     *
     * @param TypeBuildersOptions $options
     */
    private function addComponentAttribute(array $options): void
    {
        ['builder' => $builder, 'attributeName' => $attributeName, 'contentType' => $contentType, 'attribute' => $attribute] = $options;

        $localBuilder = $builder;

        $naming = $this->utils()->naming;
        $getContentTypeArgs = $this->builders()->utils->getContentTypeArgs(...);
        $buildComponentResolver = $this->contentApiBuilders()->buildComponentResolver(...);

        $type = $naming->getComponentNameFromAttribute($attribute);

        if (($attribute['repeatable'] ?? false) === true) {
            $localBuilder = $localBuilder->list;
        }

        $targetComponent = $this->strapi->getModel((string) ($attribute['component'] ?? ''));
        if ($targetComponent === null) {
            return;
        }

        $resolve = $buildComponentResolver([
            'contentTypeUID' => $contentType->uid,
            'attributeName' => $attributeName,
        ]);

        $args = $getContentTypeArgs($targetComponent, [
            'multiple' => ($attribute['repeatable'] ?? false) === true,
            'isNested' => true,
        ]);

        $localBuilder->field($attributeName, ['type' => $type, 'resolve' => $resolve, 'args' => $args]);
    }

    /**
     * Add a dynamic zone attribute to the type definition
     *
     * The attribute is added by fetching the dynamic zone's
     * type name and using it as the attribute's type
     *
     * @param TypeBuildersOptions $options
     */
    private function addDynamicZoneAttribute(array $options): void
    {
        ['builder' => $builder, 'attributeName' => $attributeName, 'contentType' => $contentType] = $options;

        $naming = $this->utils()->naming;

        $components = $contentType->attributes[$attributeName]['components'] ?? [];

        $isEmpty = !is_array($components) || count($components) === 0;
        $type = $naming->getDynamicZoneName($contentType, $attributeName);

        $resolve = $isEmpty
            // If the dynamic zone don't have any component, then return an error payload
            ? static fn (): array => [
                'code' => Constants::ERROR_CODES['emptyDynamicZone'],
                'message' => "This dynamic zone don't have any component attached to it",
            ]
            //  Else, return a classic dynamic-zone resolver
            : $this->contentApiBuilders()->buildDynamicZoneResolver([
                'contentTypeUID' => $contentType->uid,
                'attributeName' => $attributeName,
            ]);

        $builder->list->field($attributeName, ['type' => $type, 'resolve' => $resolve]);
    }

    /**
     * Add an enum attribute to the type definition
     *
     * The attribute is added by fetching the enum's type
     * name and using it as the attribute's type
     *
     * @param TypeBuildersOptions $options
     */
    private function addEnumAttribute(array $options): void
    {
        ['builder' => $builder, 'attributeName' => $attributeName, 'contentType' => $contentType] = $options;

        $type = $this->utils()->naming->getEnumName($contentType, $attributeName);

        $builder->field($attributeName, ['type' => $type]);
    }

    /**
     * Add a media attribute to the type definition
     *
     * @param TypeBuildersOptions $options
     */
    private function addMediaAttribute(array $options): void
    {
        $naming = $this->utils()->naming;
        $getContentTypeArgs = $this->builders()->utils->getContentTypeArgs(...);
        $buildAssociationResolver = $this->contentApiBuilders()->buildAssociationResolver(...);
        $extension = $this->extension();

        ['builder' => $builder, 'attributeName' => $attributeName, 'attribute' => $attribute, 'contentType' => $contentType] = $options;
        $fileUID = 'plugin::upload.file';

        if ($extension->shadowCRUD($fileUID)->isDisabled()) {
            return;
        }

        $fileContentType = $this->strapi->contentTypes()[$fileUID] ?? null;
        if ($fileContentType === null) {
            return;
        }

        $resolve = $buildAssociationResolver([
            'contentTypeUID' => $contentType->uid,
            'attributeName' => $attributeName,
        ]);

        $multiple = ($attribute['multiple'] ?? false) === true;

        $args = $multiple ? $getContentTypeArgs($fileContentType, ['isNested' => true]) : null;

        $typeName = $naming->getTypeName($fileContentType);

        if ($multiple) {
            $builder->field("{$attributeName}_connection", [
                'type' => $naming->getRelationResponseCollectionName($fileContentType),
                'resolve' => $resolve,
                'args' => $args,
            ]);

            $builder->field($attributeName, [
                'type' => Nexus::nonNull(Nexus::list($typeName)),
                'resolve' => static function (mixed ...$args) use ($resolve): mixed {
                    $res = $resolve(...$args);

                    return is_array($res) ? ($res['nodes'] ?? null) ?? [] : [];
                },
                'args' => $args,
            ]);
        } else {
            $builder->field($attributeName, [
                'type' => $typeName,
                'resolve' => static function (mixed ...$args) use ($resolve): mixed {
                    $res = $resolve(...$args);

                    return is_array($res) ? ($res['value'] ?? null) : null;
                },
                'args' => $args,
            ]);
        }
    }

    /**
     * Add a polymorphic relational attribute to the type definition
     *
     * @param TypeBuildersOptions $options
     */
    private function addPolymorphicRelationalAttribute(array $options): void
    {
        $naming = $this->utils()->naming;
        $buildAssociationResolver = $this->contentApiBuilders()->buildAssociationResolver(...);

        ['builder' => $builder, 'attributeName' => $attributeName, 'attribute' => $attribute, 'contentType' => $contentType] = $options;

        $target = $attribute['target'] ?? null;
        $isToManyRelation = str_ends_with((string) ($attribute['relation'] ?? ''), 'Many');

        if ($isToManyRelation) {
            $builder = $builder->list;
        }
        // todo[v4]: How to handle polymorphic relation w/ entity response collection types?
        //  -> Currently return raw polymorphic entities

        $resolve = $buildAssociationResolver([
            'contentTypeUID' => $contentType->uid,
            'attributeName' => $attributeName,
        ]);

        // If there is no specific target specified, then use the GenericMorph type
        if ($target === null) {
            $builder->field($attributeName, [
                'type' => Constants::GENERIC_MORPH_TYPENAME,
                'resolve' => $resolve,
            ]);
        }

        // If the target is an array of string, resolve the associated morph type and use it
        elseif (is_array($target) && array_is_list($target) && count(array_filter($target, 'is_string')) === count($target)) {
            $type = $naming->getMorphRelationTypeName($contentType, $attributeName);

            $builder->field($attributeName, ['type' => $type, 'resolve' => $resolve]);
        }
    }

    /**
     * Add a regular relational attribute to the type definition
     *
     * @param TypeBuildersOptions $options
     */
    private function addRegularRelationalAttribute(array $options): void
    {
        $naming = $this->utils()->naming;
        $getContentTypeArgs = $this->builders()->utils->getContentTypeArgs(...);
        $buildAssociationResolver = $this->contentApiBuilders()->buildAssociationResolver(...);
        $extension = $this->extension();

        ['builder' => $builder, 'attributeName' => $attributeName, 'attribute' => $attribute, 'contentType' => $contentType] = $options;

        $target = (string) ($attribute['target'] ?? '');

        if ($extension->shadowCRUD($target)->isDisabled()) {
            return;
        }

        $isToManyRelation = str_ends_with((string) ($attribute['relation'] ?? ''), 'Many');

        $resolve = $buildAssociationResolver([
            'contentTypeUID' => $contentType->uid,
            'attributeName' => $attributeName,
        ]);

        $targetContentType = $this->strapi->getModel($target);
        if ($targetContentType === null) {
            return;
        }

        $typeName = $naming->getTypeName($targetContentType);

        $args = $isToManyRelation ? $getContentTypeArgs($targetContentType, ['isNested' => true]) : null;

        $resolverScope = "{$targetContentType->uid}.find";
        $resolverPath = $naming->getTypeName($contentType) . ".{$attributeName}";

        $extension->use(['resolversConfig' => [$resolverPath => ['auth' => ['scope' => [$resolverScope]]]]]);

        if ($isToManyRelation) {
            $builder->field("{$attributeName}_connection", [
                'type' => $naming->getRelationResponseCollectionName($targetContentType),
                'resolve' => $resolve,
                'args' => $args,
            ]);

            $extension->use([
                'resolversConfig' => ["{$resolverPath}_connection" => ['auth' => ['scope' => [$resolverScope]]]],
            ]);

            $builder->field($attributeName, [
                'type' => Nexus::nonNull(Nexus::list($typeName)),
                'resolve' => static function (mixed ...$args) use ($resolve): mixed {
                    $res = $resolve(...$args);

                    return is_array($res) ? ($res['nodes'] ?? null) ?? [] : [];
                },
                'args' => $args,
            ]);
        } else {
            $builder->field($attributeName, [
                'type' => $typeName,
                'resolve' => static function (mixed ...$args) use ($resolve): mixed {
                    $res = $resolve(...$args);

                    return is_array($res) ? ($res['value'] ?? null) : null;
                },
                'args' => $args,
            ]);
        }
    }

    private function isNotPrivate(Schema $contentType, string $attributeName): bool
    {
        return !ContentTypes::isPrivateAttribute($contentType, $attributeName);
    }

    private function isNotDisabled(Schema $contentType, string $attributeName): bool
    {
        return $this->extension()->shadowCRUD($contentType->uid)->field($attributeName)->hasOutputEnabled();
    }

    /**
     * Create a type definition for a given content type
     *
     * @param Schema $contentType - The content type used to created the definition
     */
    public function buildTypeDefinition(Schema $contentType): ObjectTypeDef
    {
        $utils = $this->utils();
        $attributesUtils = $utils->attributes;

        $attributes = $contentType->attributes;
        $modelType = $contentType->modelType;

        $attributesKey = array_map('strval', array_keys($attributes));

        $name = $modelType === 'component' ? $utils->naming->getComponentName($contentType) : $utils->naming->getTypeName($contentType);

        $strapi = $this->strapi;

        return Nexus::objectType([
            'name' => $name,
            'definition' => function (OutputDefinitionBlock $t) use ($strapi, $contentType, $modelType, $name, $attributes, $attributesKey, $attributesUtils): void {
                $v4CompatibilityMode = $strapi->plugin('graphql')->config('v4CompatibilityMode', false);

                // add back the old id attribute on contentType if v4 compat is enabled
                if ($modelType !== 'component' && $this->isNotDisabled($contentType, 'id') && $v4CompatibilityMode) {
                    $t->nonNull->id('id', [
                        'deprecation' => 'Use `documentId` instead',
                    ]);
                }

                if ($modelType === 'component' && $this->isNotDisabled($contentType, 'id')) {
                    $t->nonNull->id('id');
                }

                if ($modelType !== 'component' && $this->isNotDisabled($contentType, 'documentId')) {
                    $t->nonNull->id('documentId');
                }

                if ($v4CompatibilityMode) {
                    $t->nonNull->field('attributes', [
                        'deprecation' => 'Use root level fields instead',
                        'type' => $name,
                        'resolve' => static fn (mixed $parent): mixed => $parent,
                    ]);

                    $t->nonNull->field('data', [
                        'deprecation' => 'Use root level fields instead',
                        'type' => $name,
                        'resolve' => static fn (mixed $parent): mixed => $parent,
                    ]);
                }

                /** Attributes
                 *
                 * Attributes can be of 7 different kind:
                 * - Scalar
                 * - Component
                 * - Dynamic Zone
                 * - Enum
                 * - Media
                 * - Polymorphic Relations
                 * - Regular Relations
                 *
                 * Here, we iterate over each non-private attribute
                 * and add it to the type definition based on its type
                 */
                foreach ($attributesKey as $attributeName) {
                    // Ignore private attributes
                    if (!$this->isNotPrivate($contentType, $attributeName)) {
                        continue;
                    }
                    // Ignore disabled fields (from extension service)
                    if (!$this->isNotDisabled($contentType, $attributeName)) {
                        continue;
                    }

                    // Add each attribute to the type definition
                    $attribute = $attributes[$attributeName];

                    // We create a copy of the builder (t) to apply custom
                    // rules only on the current attribute (eg: nonNull, list, ...)
                    $builder = $t;

                    if (($attribute['required'] ?? false) === true) {
                        $builder = $builder->nonNull;
                    }

                    $options = [
                        'builder' => $builder,
                        'attributeName' => $attributeName,
                        'attribute' => $attribute,
                        'contentType' => $contentType,
                    ];

                    // Enums
                    if ($attributesUtils->isEnumeration($attribute)) {
                        $this->addEnumAttribute($options);
                    }

                    // Scalars
                    elseif ($attributesUtils->isStrapiScalar($attribute)) {
                        $this->addScalarAttribute($options);
                    }

                    // Components
                    elseif ($attributesUtils->isComponent($attribute)) {
                        $this->addComponentAttribute($options);
                    }

                    // Dynamic Zones
                    elseif ($attributesUtils->isDynamicZone($attribute)) {
                        $this->addDynamicZoneAttribute($options);
                    }

                    // Media
                    elseif ($attributesUtils->isMedia($attribute)) {
                        $this->addMediaAttribute($options);
                    }

                    // Polymorphic Relations
                    elseif ($attributesUtils->isMorphRelation($attribute)) {
                        $this->addPolymorphicRelationalAttribute($options);
                    }

                    // Regular Relations
                    elseif ($attributesUtils->isRelation($attribute)) {
                        $this->addRegularRelationalAttribute($options);
                    }
                }
            },
        ]);
    }
}
