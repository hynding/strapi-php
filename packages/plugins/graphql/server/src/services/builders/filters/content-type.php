<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\InputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Builders\Filters\Operators\Operator;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of server/src/services/builders/filters/content-type.ts */
final class ContentType
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function utils(): Utils
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        return $utils;
    }

    private function extension(): Extension
    {
        $extension = $this->strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        return $extension;
    }

    /** @return list<Operator> */
    private function rootLevelOperators(): array
    {
        $builders = $this->strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);
        $operators = $builders->filters->operators;

        return [$operators['and'], $operators['or'], $operators['not']];
    }

    /** @param array<string, mixed> $attribute */
    private function addScalarAttribute(InputDefinitionBlock $builder, string $attributeName, array $attribute): void
    {
        $utils = $this->utils();

        $gqlType = (string) $utils->mappers->strapiScalarToGraphQLScalar($attribute['type'] ?? null);

        $builder->field($attributeName, ['type' => $utils->naming->getScalarFilterInputTypeName($gqlType)]);
    }

    /** @param array<string, mixed> $attribute */
    private function addRelationalAttribute(InputDefinitionBlock $builder, string $attributeName, array $attribute): void
    {
        $utils = $this->utils();

        $model = array_key_exists('target', $attribute) && is_string($attribute['target']) ? $this->strapi->getModel($attribute['target']) : null;

        // If there is no model corresponding to the attribute configuration
        // or if the attribute is a polymorphic relation, then ignore it
        if ($model === null || $utils->attributes->isMorphRelation($attribute)) {
            return;
        }

        // If the target model is disabled, then ignore it too
        if ($this->extension()->shadowCRUD($model->uid)->isDisabled()) {
            return;
        }

        $builder->field($attributeName, ['type' => $utils->naming->getFiltersInputTypeName($model)]);
    }

    /** @param array<string, mixed> $attribute */
    private function addComponentAttribute(InputDefinitionBlock $builder, string $attributeName, array $attribute): void
    {
        $utils = $this->utils();

        $component = $this->strapi->getModel((string) ($attribute['component'] ?? ''));

        // If there is no component corresponding to the attribute configuration, then ignore it
        if ($component === null) {
            return;
        }

        // If the component is disabled, then ignore it too
        if ($this->extension()->shadowCRUD($component->uid)->isDisabled()) {
            return;
        }

        $builder->field($attributeName, ['type' => $utils->naming->getFiltersInputTypeName($component)]);
    }

    public function buildContentTypeFilters(Schema $contentType): InputObjectTypeDef
    {
        $utils = $this->utils();
        $extension = $this->extension();

        $attributes = $contentType->attributes;

        $filtersTypeName = $utils->naming->getFiltersInputTypeName($contentType);

        return Nexus::inputObjectType([
            'name' => $filtersTypeName,

            'definition' => function (InputDefinitionBlock $t) use ($utils, $extension, $contentType, $attributes, $filtersTypeName): void {
                $validAttributes = [];
                foreach ($attributes as $attributeName => $attribute) {
                    $attributeName = (string) $attributeName;
                    // Remove private attributes
                    if (ContentTypes::isPrivateAttribute($contentType, $attributeName)) {
                        continue;
                    }
                    // Remove attributes that have been disabled using the shadow CRUD extension API
                    if (!$extension->shadowCRUD($contentType->uid)->field($attributeName)->hasFiltersEnabeld()) {
                        continue;
                    }
                    $validAttributes[$attributeName] = $attribute;
                }

                $isIDFilterEnabled = $extension
                    ->shadowCRUD($contentType->uid)
                    ->field('documentId')
                    ->hasFiltersEnabeld();

                // Add an ID filter to the collection types
                if ($contentType->kind === 'collectionType' && $isIDFilterEnabled) {
                    $t->field('documentId', ['type' => $utils->naming->getScalarFilterInputTypeName('ID')]);
                }

                // Add every defined attribute
                foreach ($validAttributes as $attributeName => $attribute) {
                    // Handle scalars
                    if ($utils->attributes->isStrapiScalar($attribute)) {
                        $this->addScalarAttribute($t, $attributeName, $attribute);
                    }

                    // Handle relations
                    elseif ($utils->attributes->isRelation($attribute)) {
                        $this->addRelationalAttribute($t, $attributeName, $attribute);
                    }

                    // Handle components
                    elseif ($utils->attributes->isComponent($attribute)) {
                        $this->addComponentAttribute($t, $attributeName, $attribute);
                    }
                }

                // Conditional clauses
                foreach ($this->rootLevelOperators() as $operator) {
                    $operator->add($t, $filtersTypeName);
                }
            },
        ]);
    }
}
