<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils\Configuration;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Qs;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Port of server/src/services/utils/configuration/settings.ts. */
final class Settings
{
    /** General settings */
    public const array DEFAULT_SETTINGS = [
        'bulkable' => true,
        'filterable' => true,
        'searchable' => true,
        'pageSize' => 10,
        'relationOpenMode' => 'modal',
    ];

    private const array SETTINGS_FIELDS = [
        'searchable',
        'filterable',
        'bulkable',
        'pageSize',
        'mainField',
        'defaultSortBy',
        'defaultSortOrder',
        'relationOpenMode',
    ];

    /**
     * @param array<string, mixed> $schema
     * @return array<mixed>
     */
    private static function getModelSettings(array $schema): array
    {
        $settings = $schema['config']['settings'] ?? [];

        return is_array($settings) ? Objects::pick($settings, self::SETTINGS_FIELDS) : [];
    }

    /** @param array<string, mixed> $schema */
    public static function isValidDefaultSort(Strapi $strapi, array $schema, mixed $value): bool
    {
        $parsedValue = Qs::parse(is_string($value) || is_array($value) ? $value : null);

        $omitNonSortableAttributes = static function (VisitorOptions $options, VisitorUtils $utils) use ($strapi): void {
            $sortableAttributes = Attributes::getSortableAttributes($strapi, is_array($options->schema) ? $options->schema : \Strapi\Utils\ContentTypes::toArray($options->schema));
            if (!in_array($options->key, $sortableAttributes, true)) {
                $utils->remove($options->key);
            }
        };

        $sanitizedValue = QuerySort::traverse(
            $omitNonSortableAttributes,
            ['schema' => $schema, 'getModel' => static fn (string $uid) => $strapi->getModel($uid)],
            $parsedValue,
        );

        // If any of the keys has been removed, the sort attribute is not valid
        return $parsedValue == $sanitizedValue;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function createDefaultSettings(array $schema): array
    {
        $defaultField = Attributes::getDefaultMainField($schema);

        return [
            ...self::DEFAULT_SETTINGS,
            'mainField' => $defaultField,
            'defaultSortBy' => $defaultField,
            'defaultSortOrder' => 'ASC',
            ...self::getModelSettings($schema),
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function syncSettings(Strapi $strapi, array $configuration, array $schema): array
    {
        if (Objects::isEmpty($configuration['settings'] ?? null)) {
            return self::createDefaultSettings($schema);
        }

        $defaultField = Attributes::getDefaultMainField($schema);

        $settings = is_array($configuration['settings']) ? $configuration['settings'] : [];
        $mainField = $settings['mainField'] ?? $defaultField;
        $defaultSortBy = $settings['defaultSortBy'] ?? $defaultField;

        return [
            ...$settings,
            'mainField' => Attributes::isSortable($schema, $mainField) ? $mainField : $defaultField,
            'defaultSortBy' => self::isValidDefaultSort($strapi, $schema, $defaultSortBy) ? $defaultSortBy : $defaultField,
        ];
    }
}
