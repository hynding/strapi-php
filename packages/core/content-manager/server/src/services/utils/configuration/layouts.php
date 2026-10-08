<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils\Configuration;

use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/services/utils/configuration/layouts.ts. */
final class Layouts
{
    private const int DEFAULT_LIST_LENGTH = 4;
    private const int MAX_ROW_SIZE = 12;

    private static function isAllowedFieldSize(Strapi $strapi, string $type, mixed $size): bool
    {
        $fieldSize = Utils::getService($strapi, 'field-sizes')->getFieldSize($type);

        // Check if field was locked to another size
        if (!$fieldSize['isResizable'] && $size !== $fieldSize['default']) {
            return false;
        }

        // Otherwise allow unless it's bigger than a row
        return is_int($size) || is_float($size) ? $size <= self::MAX_ROW_SIZE : false;
    }

    /** @param array<string, mixed> $attribute */
    private static function getDefaultFieldSize(Strapi $strapi, array $attribute): int
    {
        $fieldSizes = Utils::getService($strapi, 'field-sizes');
        $customField = $attribute['customField'] ?? null;

        // Check if it's a custom field with a custom size and get the default size for the field type
        $type = is_string($customField) && $fieldSizes->hasFieldSize($customField) ? $customField : ($attribute['type'] ?? null);

        return (int) $fieldSizes->getFieldSize(is_string($type) ? $type : null)['default'];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{list: list<string>, edit: list<list<array{name: string, size: int|float}>>}
     */
    public static function createDefaultLayouts(Strapi $strapi, array $schema): array
    {
        $configLayouts = $schema['config']['layouts'] ?? [];

        /** @var array{list: list<string>, edit: list<list<array{name: string, size: int|float}>>} */
        return [
            'list' => self::createDefaultListLayout($schema),
            'edit' => self::createDefaultEditLayout($strapi, $schema),
            ...(is_array($configLayouts) ? Objects::pick($configLayouts, ['list', 'edit']) : []),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function createDefaultListLayout(array $schema): array
    {
        $keys = array_values(array_filter(
            array_map('strval', array_keys($schema['attributes'] ?? [])),
            static fn (string $name): bool => Attributes::isListable($schema, $name) && $name !== 'documentId',
        ));

        return array_slice($keys, 0, self::DEFAULT_LIST_LENGTH);
    }

    /** @param list<array<string, mixed>> $els */
    private static function rowSize(array $els): int|float
    {
        $sum = 0;
        foreach ($els as $el) {
            $size = $el['size'] ?? 0;
            $sum += is_int($size) || is_float($size) ? $size : 0;
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<list<array{name: string, size: int|float}>>
     */
    private static function createDefaultEditLayout(Strapi $strapi, array $schema): array
    {
        $keys = array_values(array_filter(
            array_map('strval', array_keys($schema['attributes'] ?? [])),
            static fn (string $name): bool => Attributes::hasEditableAttribute($schema, $name),
        ));

        return self::appendToEditLayout($strapi, [], $keys, $schema);
    }

    /** Synchronisation functions */

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function syncLayouts(Strapi $strapi, array $configuration, array $schema): array
    {
        if (Objects::isEmpty($configuration['layouts'] ?? null)) {
            return self::createDefaultLayouts($strapi, $schema);
        }

        $layouts = is_array($configuration['layouts']) ? $configuration['layouts'] : [];
        $list = is_array($layouts['list'] ?? null) ? $layouts['list'] : [];
        $editRelations = is_array($layouts['editRelations'] ?? null) ? $layouts['editRelations'] : [];
        $edit = is_array($layouts['edit'] ?? null) ? $layouts['edit'] : [];

        $cleanList = array_values(array_filter($list, static fn (mixed $attr): bool => Attributes::isListable($schema, $attr)));

        // TODO V5: remove editRelations
        $cleanEditRelations = array_values(array_filter($editRelations, static fn (mixed $attr): bool => Attributes::hasRelationAttribute($schema, $attr)));

        // backward compatibility with when relations were on the side of the layout
        // it migrates the displayed relations to the main edit layout
        $elementsToReAppend = $cleanEditRelations;
        $cleanEdit = [];
        $fieldSizes = Utils::getService($strapi, 'field-sizes');
        foreach ($edit as $row) {
            $newRow = [];

            foreach (is_array($row) ? $row : [] as $el) {
                $name = is_array($el) ? ($el['name'] ?? null) : null;
                if (!Attributes::hasEditableAttribute($schema, $name)) {
                    continue;
                }

                // Check if the field is a custom field with a custom size.
                // If so, use the custom size instead of the type size
                $attribute = $schema['attributes'][$name];
                $customField = $attribute['customField'] ?? null;
                $fieldType = is_string($customField) && $fieldSizes->hasFieldSize($customField)
                    ? $customField
                    : (string) ($attribute['type'] ?? '');

                /* if the type of a field was changed (ex: string -> json) or a new field was added in the schema
                   and the new type doesn't allow the size of the previous type, append the field at the end of layouts
                */
                if (!self::isAllowedFieldSize($strapi, $fieldType, $el['size'] ?? null)) {
                    $elementsToReAppend[] = $name;
                    continue;
                }

                $newRow[] = $el;
            }

            if ($newRow !== []) {
                $cleanEdit[] = $newRow;
            }
        }

        $cleanEdit = self::appendToEditLayout($strapi, $cleanEdit, $elementsToReAppend, $schema);

        $metadatas = is_array($configuration['metadatas'] ?? null) ? $configuration['metadatas'] : [];
        $newAttributes = array_values(array_diff(
            array_map('strval', array_keys($schema['attributes'] ?? [])),
            array_map('strval', array_keys($metadatas)),
        ));

        /** Add new attributes where they belong */

        if (count($cleanList) < self::DEFAULT_LIST_LENGTH) {
            // add newAttributes
            // only add valid listable attributes
            $cleanList = array_values(array_unique(array_slice(
                [...$cleanList, ...array_values(array_filter($newAttributes, static fn (string $key): bool => Attributes::isListable($schema, $key)))],
                0,
                self::DEFAULT_LIST_LENGTH,
            )));
        }

        // add new attributes to edit view
        $newEditAttributes = array_values(array_filter($newAttributes, static fn (string $key): bool => Attributes::hasEditableAttribute($schema, $key)));

        $cleanEdit = self::appendToEditLayout($strapi, $cleanEdit, $newEditAttributes, $schema);

        return [
            'list' => $cleanList !== [] ? $cleanList : self::createDefaultListLayout($schema),
            'edit' => $cleanEdit !== [] ? $cleanEdit : self::createDefaultEditLayout($strapi, $schema),
        ];
    }

    /**
     * @param list<mixed> $layout
     * @param list<mixed> $keysToAppend
     * @param array<string, mixed> $schema
     * @return list<mixed>
     */
    private static function appendToEditLayout(Strapi $strapi, array $layout, array $keysToAppend, array $schema): array
    {
        if ($keysToAppend === []) {
            return $layout;
        }
        $currentRowIndex = max(count($layout) - 1, 0);

        // init currentRow if necessary
        if (!isset($layout[$currentRowIndex])) {
            $layout[$currentRowIndex] = [];
        }

        foreach ($keysToAppend as $key) {
            $attribute = $schema['attributes'][$key] ?? [];

            $attributeSize = self::getDefaultFieldSize($strapi, is_array($attribute) ? $attribute : []);
            $currenRowSize = self::rowSize($layout[$currentRowIndex]);

            if ($currenRowSize + $attributeSize > self::MAX_ROW_SIZE) {
                $currentRowIndex += 1;
                $layout[$currentRowIndex] = [];
            }

            $layout[$currentRowIndex][] = [
                'name' => $key,
                'size' => $attributeSize,
            ];
        }

        return array_values($layout);
    }
}
