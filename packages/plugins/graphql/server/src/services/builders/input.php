<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\InputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of server/src/services/builders/input.ts */
final class Input
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function buildInputType(Schema $contentType): InputObjectTypeDef
    {
        $strapi = $this->strapi;
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $extension = $strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        $naming = $utils->naming;
        $mappers = $utils->mappers;
        $attributesUtils = $utils->attributes;

        $attributes = $contentType->attributes;
        $modelType = $contentType->modelType;

        $name = $modelType === 'component' ? $naming->getComponentInputName($contentType) : $naming->getContentTypeInputName($contentType);

        return Nexus::inputObjectType([
            'name' => $name,

            'definition' => static function (InputDefinitionBlock $t) use ($strapi, $extension, $naming, $mappers, $attributesUtils, $contentType, $attributes, $modelType): void {
                $isFieldEnabled = static fn (string $fieldName): bool => $extension->shadowCRUD($contentType->uid)->field($fieldName)->hasInputEnabled();

                $validAttributes = [];
                foreach ($attributes as $attributeName => $attribute) {
                    $attributeName = (string) $attributeName;
                    // Remove non-writable attributes
                    if (!ContentTypes::isWritableAttribute($contentType, $attributeName)) {
                        continue;
                    }
                    // Remove filters that have been disabled using the shadow CRUD extension API
                    if (!$isFieldEnabled($attributeName)) {
                        continue;
                    }
                    $validAttributes[$attributeName] = $attribute;
                }

                // Add the ID for the component to enable inplace updates
                if ($modelType === 'component' && $isFieldEnabled('id')) {
                    $t->id('id');
                }

                foreach ($validAttributes as $attributeName => $attribute) {
                    // Enums
                    if ($attributesUtils->isEnumeration($attribute)) {
                        $enumTypeName = $naming->getEnumName($contentType, $attributeName);

                        $t->field($attributeName, ['type' => $enumTypeName]);
                    }

                    // Scalars
                    elseif ($attributesUtils->isStrapiScalar($attribute)) {
                        $gqlScalar = $mappers->strapiScalarToGraphQLScalar($attribute['type'] ?? null);

                        $t->field($attributeName, ['type' => $gqlScalar]);
                    }

                    // Media
                    elseif ($attributesUtils->isMedia($attribute)) {
                        $isMultiple = ($attribute['multiple'] ?? null) === true;

                        if ($extension->shadowCRUD('plugin::upload.file')->isDisabled()) {
                            continue;
                        }

                        if ($isMultiple) {
                            $t->list->id($attributeName);
                        } else {
                            $t->id($attributeName);
                        }
                    }

                    // Regular Relations (ignore polymorphic relations)
                    elseif ($attributesUtils->isRelation($attribute) && !$attributesUtils->isMorphRelation($attribute)) {
                        if ($extension->shadowCRUD((string) ($attribute['target'] ?? ''))->isDisabled()) {
                            continue;
                        }

                        $isToManyRelation = str_ends_with((string) ($attribute['relation'] ?? ''), 'Many');

                        if ($isToManyRelation) {
                            $t->list->id($attributeName);
                        } else {
                            $t->id($attributeName);
                        }
                    }

                    // Components
                    elseif ($attributesUtils->isComponent($attribute)) {
                        $isRepeatable = ($attribute['repeatable'] ?? null) === true;
                        $component = $strapi->components()[(string) ($attribute['component'] ?? '')] ?? null;
                        if ($component === null) {
                            continue;
                        }
                        $componentInputType = $naming->getComponentInputName($component);

                        if ($isRepeatable) {
                            $t->list->field($attributeName, ['type' => $componentInputType]);
                        } else {
                            $t->field($attributeName, ['type' => $componentInputType]);
                        }
                    }

                    // Dynamic Zones
                    elseif ($attributesUtils->isDynamicZone($attribute)) {
                        $dzInputName = $naming->getDynamicZoneInputName($contentType, $attributeName);

                        $t->list->field($attributeName, ['type' => Nexus::nonNull($dzInputName)]);
                    }
                }
            },
        ]);
    }
}
