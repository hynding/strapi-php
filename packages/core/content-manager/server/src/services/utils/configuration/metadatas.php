<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils\Configuration;

use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/services/utils/configuration/metadatas.ts. */
final class Metadatas
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, array{edit: array<mixed>, list: array<mixed>}>
     */
    public static function createDefaultMetadatas(Strapi $strapi, array $schema): array
    {
        $metadatas = [];
        foreach (array_keys(is_array($schema['attributes'] ?? null) ? $schema['attributes'] : []) as $name) {
            $metadatas[(string) $name] = self::createDefaultMetadata($strapi, $schema, (string) $name);
        }

        $metadatas['id'] = [
            'edit' => [],
            'list' => [
                'label' => 'id',
                'searchable' => true,
                'sortable' => true,
            ],
        ];
        $metadatas['documentId'] = [
            'edit' => [],
            'list' => [
                'label' => 'documentId',
                'searchable' => true,
                'sortable' => true,
            ],
        ];

        return $metadatas;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{edit: array<mixed>, list: array<mixed>}
     */
    private static function createDefaultMetadata(Strapi $strapi, array $schema, string $name): array
    {
        $edit = [
            'label' => $name,
            'description' => '',
            'placeholder' => '',
            'visible' => Attributes::isVisible($schema, $name),
            'editable' => true,
        ];

        $fieldAttributes = $schema['attributes'][$name] ?? null;
        if (Attributes::isRelation($fieldAttributes)) {
            $targetModel = $fieldAttributes['targetModel'] ?? null;

            $targetSchema = self::getTargetSchema($strapi, $targetModel);

            if ($targetSchema !== null) {
                $edit['mainField'] = Attributes::getDefaultMainField($targetSchema);
            }
        }

        $configEdit = $schema['config']['metadatas'][$name]['edit'] ?? [];
        $edit = [
            ...$edit,
            ...(is_array($configEdit) ? Objects::pick($configEdit, ['label', 'description', 'placeholder', 'visible', 'editable', 'mainField']) : []),
        ];

        $configList = $schema['config']['metadatas'][$name]['list'] ?? [];
        $list = [
            'label' => $name,
            'searchable' => Attributes::isSearchable($schema, $name),
            'sortable' => Attributes::isSortable($schema, $name),
            ...(is_array($configList) ? Objects::pick($configList, ['label', 'searchable', 'sortable']) : []),
        ];

        return ['edit' => $edit, 'list' => $list];
    }

    /** Synchronisation functions */

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $schema
     * @return array<mixed>
     */
    public static function syncMetadatas(Strapi $strapi, array $configuration, array $schema): array
    {
        // clear all keys that do not exist anymore
        if (Objects::isEmpty($configuration['metadatas'] ?? null)) {
            return self::createDefaultMetadatas($strapi, $schema);
        }

        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];

        // remove old keys
        $metasWithValidKeys = Objects::pick(
            is_array($configuration['metadatas']) ? $configuration['metadatas'] : [],
            array_map('strval', array_keys($attributes)),
        );

        // add new keys and missing fields
        $metasWithDefaults = Objects::merge(self::createDefaultMetadatas($strapi, $schema), $metasWithValidKeys);

        // clear the invalid mainFields
        $updatedMetas = [];
        foreach ($metasWithDefaults as $key => $meta) {
            $edit = is_array($meta['edit'] ?? null) ? $meta['edit'] : [];
            $list = is_array($meta['list'] ?? null) ? $meta['list'] : [];
            $attr = $attributes[$key] ?? null;

            $updatedMeta = ['edit' => $edit, 'list' => $list];

            // update sortable attr
            if (!empty($list['sortable']) && !Attributes::isSortable($schema, $key)) {
                $updatedMeta['list']['sortable'] = false;
                $updatedMetas[$key] = $updatedMeta;
            }

            if (!empty($list['searchable']) && !Attributes::isSearchable($schema, $key)) {
                $updatedMeta['list']['searchable'] = false;
                $updatedMetas[$key] = $updatedMeta;
            }

            if (!array_key_exists('mainField', $edit)) {
                continue;
            }

            // remove mainField if the attribute is not a relation anymore
            if (!Attributes::isRelation($attr)) {
                $updatedMeta['edit'] = Objects::omit($edit, ['mainField']);
                $updatedMetas[$key] = $updatedMeta;
                continue;
            }

            // if the mainField is id you can keep it
            if ($edit['mainField'] === 'id') {
                continue;
            }

            // check the mainField in the targetModel
            $targetSchema = self::getTargetSchema($strapi, $attr['targetModel'] ?? null);

            if ($targetSchema === null) {
                continue;
            }

            if (!Attributes::isSortable($targetSchema, $edit['mainField']) && !Attributes::isListable($targetSchema, $edit['mainField'])) {
                $updatedMeta['edit']['mainField'] = Attributes::getDefaultMainField($targetSchema);
                $updatedMetas[$key] = $updatedMeta;
                continue;
            }
        }

        return [...$metasWithDefaults, ...$updatedMetas];
    }

    /** @return array<string, mixed>|null */
    private static function getTargetSchema(Strapi $strapi, mixed $targetModel): ?array
    {
        return is_string($targetModel) ? Utils::getService($strapi, 'content-types')->findContentType($targetModel) : null;
    }
}
