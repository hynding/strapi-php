<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Validation;

use Strapi\ContentManager\Services\Utils\Configuration\Attributes;
use Strapi\ContentManager\Services\Utils\Configuration\Settings;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/model-configuration.ts. */
final class ModelConfiguration
{
    /**
     * Creates the validation schema for content-type configurations
     *
     * @param array<string, mixed> $schema
     * @param array{allowUndefined?: bool} $opts
     */
    public static function createModelConfigurationSchema(Strapi $strapi, array $schema, array $opts = []): YupObject
    {
        return Yup::object()
            ->shape([
                'settings' => self::createSettingsSchema($strapi, $schema)->default(null)->nullable(),
                'metadatas' => self::createMetadasSchema($strapi, $schema)->default(null)->nullable(),
                'layouts' => self::createLayoutsSchema($schema, $opts)->default(null)->nullable(),
                'options' => Yup::object()->optional(),
            ])
            ->noUnknown();
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private static function listableAttributes(array $schema): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys(is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [])),
            static fn (string $key): bool => Attributes::isListable($schema, $key),
        ));
    }

    /** @param array<string, mixed> $schema */
    private static function createSettingsSchema(Strapi $strapi, array $schema): YupObject
    {
        $validAttributes = self::listableAttributes($schema);

        return Yup::object()
            ->shape([
                'bulkable' => Yup::boolean()->required(),
                'filterable' => Yup::boolean()->required(),
                'pageSize' => Yup::number()->integer()->min(10)->max(100)->required(),
                'searchable' => Yup::boolean()->required(),
                // should be reset when the type changes
                'mainField' => Yup::string()->oneOf([...$validAttributes, 'id'])->default('id'),
                // should be reset when the type changes
                'defaultSortBy' => Yup::string()
                    ->test(
                        'is-valid-sort-attribute',
                        '${path} is not a valid sort attribute',
                        static fn (mixed $value): bool => Settings::isValidDefaultSort($strapi, $schema, $value instanceof Undefined ? null : $value),
                    )
                    ->default('id'),
                'defaultSortOrder' => Yup::string()->oneOf(['ASC', 'DESC'])->default('ASC'),
                'relationOpenMode' => Yup::string()->oneOf(['modal', 'page', 'newTab'])->default('modal'),
            ])
            ->noUnknown();
    }

    /** @param array<string, mixed> $schema */
    private static function createMetadasSchema(Strapi $strapi, array $schema): YupObject
    {
        $shape = [];
        foreach (array_keys(is_array($schema['attributes'] ?? null) ? $schema['attributes'] : []) as $key) {
            $key = (string) $key;
            $shape[$key] = Yup::object()
                ->shape([
                    'edit' => Yup::object()
                        ->shape([
                            'label' => Yup::string(),
                            'description' => Yup::string()->nullable(),
                            'placeholder' => Yup::string()->nullable(),
                            'editable' => Yup::boolean(),
                            'visible' => Yup::boolean(),
                            'mainField' => Yup::lazy(static function (mixed $value) use ($strapi, $schema, $key): Yup\Yup {
                                if ($value === null || $value === '' || $value === false || $value instanceof Undefined) {
                                    return Yup::string();
                                }

                                $targetModel = $schema['attributes'][$key]['targetModel'] ?? null;
                                $targetSchema = is_string($targetModel)
                                    ? Utils::getService($strapi, 'content-types')->findContentType($targetModel)
                                    : null;

                                if ($targetSchema === null) {
                                    return Yup::string();
                                }

                                $validAttributes = self::listableAttributes($targetSchema);

                                return Yup::string()->oneOf([...$validAttributes, 'id'])->default('id');
                            }),
                        ])
                        ->noUnknown()
                        ->required(),
                    'list' => Yup::object()
                        ->shape([
                            'label' => Yup::string(),
                            'searchable' => Yup::boolean(),
                            'sortable' => Yup::boolean(),
                        ])
                        ->noUnknown()
                        ->required(),
                ])
                ->noUnknown();
        }

        return Yup::object()->shape($shape);
    }

    /**
     * @param array{allowUndefined?: bool} $opts
     * @return array{name: string, message: string, test: \Closure(mixed): bool}
     */
    private static function createArrayTest(array $opts = []): array
    {
        $allowUndefined = ($opts['allowUndefined'] ?? false) === true;

        return [
            'name' => 'isArray',
            'message' => '${path} is required and must be an array',
            'test' => static fn (mixed $val): bool => ($allowUndefined && $val instanceof Undefined) ? true : (is_array($val) && array_is_list($val)),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param array{allowUndefined?: bool} $opts
     */
    private static function createLayoutsSchema(array $schema, array $opts = []): YupObject
    {
        $validAttributes = self::listableAttributes($schema);

        $editAttributes = array_values(array_filter(
            array_map('strval', array_keys(is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [])),
            static fn (string $key): bool => Attributes::hasEditableAttribute($schema, $key),
        ));

        return Yup::object()->shape([
            'edit' => Yup::array()
                ->of(
                    Yup::array()->of(
                        Yup::object()
                            ->shape([
                                'name' => Yup::string()->oneOf($editAttributes)->required(),
                                'size' => Yup::number()->integer()->positive()->required(),
                            ])
                            ->noUnknown()
                    )
                )
                ->test(self::createArrayTest($opts)),
            'list' => Yup::array()->of(Yup::string()->oneOf($validAttributes))->test(self::createArrayTest($opts)),
        ]);
    }
}
