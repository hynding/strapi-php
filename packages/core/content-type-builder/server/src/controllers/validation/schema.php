<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Services\Builder;
use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\Core\Core;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Strings;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ParsePayload;
use Strapi\Utils\Zod\Undefined;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/controllers/validation/schema.ts: the payload of `POST /update-schema`.
 *
 * @phpstan-type SchemaMeta array{modelType: 'contentType'|'component', kind?: 'collectionType'|'singleType'|null}
 */
final class Schema
{
    private const array STRAPI_USER_RELATIONS = ['oneToOne', 'oneToMany'];

    // --- refinements ---------------------------------------------------------------------------

    /** @param mixed $attributes list of `{ name }` */
    private static function uniqueAttributeName(mixed $attributes, ParsePayload $ctx): void
    {
        if (!is_array($attributes)) {
            return;
        }

        $names = [];
        foreach ($attributes as $attribute) {
            $names[Strings::snakeCase((string) ($attribute['name'] ?? ''))] = true;
        }

        if (count($names) !== count($attributes)) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => 'Attributes must have unique names',
            ]);
        }
    }

    private static function verifyUidTargetField(mixed $attributes, ParsePayload $ctx): void
    {
        if (!is_array($attributes)) {
            return;
        }

        foreach ($attributes as $attribute) {
            if (!is_array($attribute['properties'] ?? null)) {
                continue;
            }

            $properties = $attribute['properties'];
            $action = $attribute['action'] ?? null;
            $targetField = $properties['targetField'] ?? null;

            if (($properties['type'] ?? null) === 'uid' && is_string($targetField) && $targetField !== '') {
                $targetAttr = null;
                foreach ($attributes as $attr) {
                    if (($attr['name'] ?? null) === $targetField) {
                        $targetAttr = $attr;
                        break;
                    }
                }

                if ($targetAttr === null) {
                    // NOTE: on update we are setting it to undefined later in the process instead to handle renames
                    if ($action === 'create') {
                        $ctx->addIssue([
                            'code' => 'custom',
                            'message' => 'Target does not exist',
                        ]);
                    }
                } elseif (!in_array($targetAttr['properties']['type'] ?? null, Constants::VALID_UID_TARGETS, true)) {
                    $ctx->addIssue([
                        'code' => 'custom',
                        'message' => 'Invalid target type',
                    ]);
                }
            }
        }
    }

    private static function verifySingularAndPluralNames(mixed $obj, ParsePayload $ctx): void
    {
        // singular and plural can only be provided on creation
        if (!is_array($obj) || ($obj['action'] ?? null) !== 'create') {
            return;
        }

        if (($obj['singularName'] ?? null) === ($obj['pluralName'] ?? null)) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => 'Singular and plural names must be different',
                'path' => ['singularName'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $contentType
     * @return list<string>
     */
    private static function getEffectiveAttributeNames(array $contentType): array
    {
        $attributes = is_array($contentType['attributes'] ?? null) ? $contentType['attributes'] : [];

        if (($contentType['action'] ?? null) === 'create') {
            $names = [];
            foreach ($attributes as $attribute) {
                if (($attribute['action'] ?? null) !== 'delete') {
                    $names[] = (string) ($attribute['name'] ?? '');
                }
            }

            return $names;
        }

        $uid = $contentType['uid'] ?? null;
        if (($contentType['action'] ?? null) !== 'update' || !is_string($uid) || $uid === '') {
            return [];
        }

        $existingContentType = Core::instance()?->contentTypes()[$uid] ?? null;
        $names = [];
        foreach ($existingContentType !== null ? array_keys($existingContentType->attributes) : [] as $name) {
            $names[(string) $name] = true;
        }

        foreach ($attributes as $attribute) {
            $name = (string) ($attribute['name'] ?? '');
            if (($attribute['action'] ?? null) === 'delete') {
                unset($names[$name]);
            } elseif (($attribute['action'] ?? null) === 'create') {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }

    public static function verifyDraftAndPublishReservedAttributes(mixed $contentType, ParsePayload $ctx): void
    {
        if (!is_array($contentType) || ($contentType['action'] ?? null) === 'delete' || !(bool) ($contentType['draftAndPublish'] ?? false)) {
            return;
        }

        $reservedAttributeNames = ContentTypes::findDraftAndPublishReservedAttributeNames(self::getEffectiveAttributeNames($contentType));

        if ($reservedAttributeNames !== []) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => ContentTypes::getDraftAndPublishEnableBlockedMessage($reservedAttributeNames),
                'path' => ['draftAndPublish'],
            ]);
        }
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    public static function maxLengthGreaterThanMinLength(mixed $value, ParsePayload $ctx): void
    {
        if (!is_array($value)) {
            return;
        }

        $max = $value['maxLength'] ?? null;
        $min = $value['minLength'] ?? null;
        if (self::isNumber($max) && self::isNumber($min) && $max < $min) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => 'maxLength must be greater or equal to minLength',
                'path' => ['maxLength'],
            ]);
        }
    }

    public static function maxGreaterThanMin(mixed $value, ParsePayload $ctx): void
    {
        if (!is_array($value)) {
            return;
        }

        $max = $value['max'] ?? null;
        $min = $value['min'] ?? null;
        if (self::isNumber($max) && self::isNumber($min) && $max < $min) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => 'max must be greater or equal to min',
                'path' => ['max'],
            ]);
        }
    }

    private static function checkUserTarget(mixed $value, ParsePayload $ctx): void
    {
        if (!is_array($value) || ($value['type'] ?? null) !== 'relation') {
            return;
        }

        if (!array_key_exists('target', $value) || !array_key_exists('relation', $value)) {
            return;
        }

        if (
            $value['target'] === Constants::CORE_UIDS['STRAPI_USER']
            && (!in_array($value['relation'], self::STRAPI_USER_RELATIONS, true) || array_key_exists('targetAttribute', $value))
        ) {
            $ctx->addIssue([
                'code' => 'custom',
                'path' => ['relation'],
                'message' => 'Relations to ' . Constants::CORE_UIDS['STRAPI_USER'] . ' must be one of the following values: ' . implode(', ', self::STRAPI_USER_RELATIONS) . ' without targetAttribute',
            ]);
        }
    }

    private static function uidRefinement(mixed $value, ParsePayload $ctx): void
    {
        if (is_array($value) && isset($value['targetField']) && isset($value['default'])) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => 'Cannot define a default UID if the targetField is set',
                'path' => ['default'],
            ]);
        }
    }

    private static function enumRefinement(mixed $value, ParsePayload $ctx): void
    {
        if (!is_array($value) || ($value['type'] ?? null) !== 'enumeration' || !isset($value['default']) || !isset($value['enum'])) {
            return;
        }

        if ($value['default'] === '' || !is_array($value['enum']) || !in_array($value['default'], $value['enum'], true)) {
            $ctx->addIssue([
                'code' => 'custom',
                'message' => 'Default value must be one of the enum values',
                'path' => ['default'],
            ]);
        }
    }

    // --- attribute schemas ---------------------------------------------------------------------

    private static function conditionsSchema(): ZodType
    {
        $conditionSchema = z::object([
            'visible' => z::record(z::string(), z::array(z::any())),
        ]);

        return z::preprocess(static fn (mixed $val): mixed => $val, $conditionSchema->optional());
    }

    private static function basePropertiesSchema(): ZodObject
    {
        return z::object([
            'type' => z::enum([
                'string',
                'text',
                'richtext',
                'blocks',
                'json',
                'enumeration',
                'password',
                'email',
                'integer',
                'biginteger',
                'float',
                'decimal',
                'time',
                'datetime',
                'date',
                'timestamp',
                'boolean',
                'component',
                'uid',
                'customField',
                'media',
                'relation',
                'dynamiczone',
            ]),
            'configurable' => z::boolean()->nullish(),
            'private' => z::boolean()->nullish(),
            // Keep null invalid so searchability is always an explicit boolean when provided.
            'searchable' => z::boolean()->optional(),
            'pluginOptions' => z::record(z::string(), z::unknown())->optional(),
            'conditions' => self::conditionsSchema(),
        ]);
    }

    private static function maxLengthSchema(): ZodType
    {
        return z::number()->int()->positive()->optional();
    }

    private static function minLengthSchema(): ZodType
    {
        return z::number()->int()->positive()->optional();
    }

    private static function requiredSchema(): ZodType
    {
        return z::boolean()->optional();
    }

    private static function uniqueSchema(): ZodType
    {
        return z::boolean()->optional();
    }

    private static function baseRelationSchema(): ZodObject
    {
        return z::object([
            'type' => z::literal('relation'),
            'relation' => z::enum([
                'oneToOne',
                'oneToMany',
                'manyToOne',
                'manyToMany',
                'morphOne',
                'morphMany',
                'morphToOne',
                'morphToMany',
            ]),
            'configurable' => z::boolean()->nullish(),
            'required' => self::requiredSchema(),
            'private' => z::boolean()->nullish(),
            'pluginOptions' => z::record(z::string(), z::unknown())->optional(),
            'conditions' => self::conditionsSchema(),
        ]);
    }

    private static function relationWithTarget(string $relation, bool $withTargetAttribute = true): ZodObject
    {
        $shape = [
            'relation' => z::literal($relation),
            'target' => z::string(),
        ];
        if ($withTargetAttribute) {
            $shape['targetAttribute'] = z::string()->nullish();
        }

        return self::baseRelationSchema()->extend($shape);
    }

    /** @param SchemaMeta $meta */
    private static function createRelationSchema(array $meta): ZodType
    {
        $oneToOne = self::relationWithTarget('oneToOne');
        $oneToMany = self::relationWithTarget('oneToMany');
        $manyToOne = self::relationWithTarget('manyToOne');
        $manyToMany = self::relationWithTarget('manyToMany');
        $morphOne = self::relationWithTarget('morphOne');
        $morphMany = self::relationWithTarget('morphMany');
        $morphToOne = self::baseRelationSchema()->extend(['relation' => z::literal('morphToOne')]);
        $morphToMany = self::baseRelationSchema()->extend(['relation' => z::literal('morphToMany')]);

        switch ($meta['modelType']) {
            case 'contentType':
                switch ($meta['kind'] ?? null) {
                    case 'singleType':
                        return z::discriminatedUnion('relation', [$oneToOne, $oneToMany, $morphOne, $morphMany, $morphToOne, $morphToMany]);
                    case 'collectionType':
                        return z::discriminatedUnion('relation', [$oneToOne, $oneToMany, $manyToOne, $manyToMany, $morphOne, $morphMany, $morphToOne, $morphToMany]);
                    default:
                        throw new \RuntimeException('Invalid content type kind');
                }

                // no break
            case 'component':
                // oneWay / manyWay
                return z::discriminatedUnion('relation', [
                    self::relationWithTarget('oneToOne', false),
                    self::relationWithTarget('oneToMany', false),
                ]);
            default:
                throw new \RuntimeException('Invalid model type');
        }
    }

    private static function regexPatternSchema(): ZodType
    {
        return z::string()
            ->optional()
            ->refine(static fn (mixed $value): bool => $value === '' || ($value instanceof Undefined) || Common::isValidRegExp($value), 'Invalid regular expression pattern');
    }

    /** @return list<ZodType> */
    private static function baseAttributeSchemas(): array
    {
        $base = self::basePropertiesSchema();

        $textSchema = $base->extend([
            'type' => z::literal('text'),
            'default' => z::string()->nullish(),
            'minLength' => self::minLengthSchema(),
            'maxLength' => self::maxLengthSchema(),
            'required' => self::requiredSchema(),
            'unique' => self::uniqueSchema(),
            'regex' => self::regexPatternSchema(),
        ]);

        $timeSchema = $base->extend([
            'type' => z::literal('time'),
            'required' => self::requiredSchema(),
            'unique' => self::uniqueSchema(),
            'default' => z::string()->optional(),
        ]);

        return [
            // media
            $base->extend([
                'type' => z::literal('media'),
                'multiple' => z::boolean()->optional(),
                'required' => self::requiredSchema(),
                'allowedTypes' => z::array(z::enum(['images', 'videos', 'files', 'audios']))->nonempty()->optional(),
            ]),
            $textSchema,
            // string
            $textSchema->extend(['type' => z::literal('string')]),
            // richtext
            $base->extend([
                'type' => z::literal('richtext'),
                'required' => self::requiredSchema(),
                'minLength' => self::minLengthSchema(),
                'maxLength' => self::maxLengthSchema(),
                'default' => z::string()->optional(),
            ]),
            // blocks
            $base->extend([
                'type' => z::literal('blocks'),
                'required' => self::requiredSchema(),
            ]),
            // json
            $base->extend([
                'type' => z::literal('json'),
                'required' => self::requiredSchema(),
                'default' => z::unknown()->optional()->refine(static function (mixed $value): bool {
                    if ($value instanceof Undefined) {
                        return true;
                    }

                    if (self::isNumber($value) || $value === null || is_array($value)) {
                        return true;
                    }

                    return json_validate(Common::jsString($value));
                }),
            ]),
            // enumeration
            $base->extend([
                'type' => z::literal('enumeration'),
                'required' => self::requiredSchema(),
                'default' => z::string()->optional(),
                'enumName' => z::string()
                    ->optional()
                    ->refine(static fn (mixed $value): bool => $value === '' || Common::regexTest(Common::NAME_REGEX, $value), 'Invalid enum name'),
                'enum' => z::array(
                    z::string()->refine(static fn (mixed $value): bool => $value === '' || !Strings::startsWithANumber((string) $value)),
                )
                    ->min(1)
                    ->refine(static function (mixed $values): bool {
                        $values = is_array($values) ? $values : [];

                        return count(array_unique(array_map('strval', $values))) === count($values);
                    }),
            ]),
            // password
            $base->extend([
                'type' => z::literal('password'),
                'required' => self::requiredSchema(),
                'minLength' => self::minLengthSchema(),
                'maxLength' => self::maxLengthSchema(),
            ]),
            // email
            $base->extend([
                'type' => z::literal('email'),
                'required' => self::requiredSchema(),
                'minLength' => self::minLengthSchema(),
                'maxLength' => self::maxLengthSchema(),
                'default' => z::string()->email()->optional(),
                'unique' => self::uniqueSchema(),
            ]),
            // integer
            $base->extend([
                'type' => z::literal('integer'),
                'required' => self::requiredSchema(),
                'default' => z::number()->int()->optional(),
                'unique' => self::uniqueSchema(),
                'min' => z::number()->int()->optional(),
                'max' => z::number()->int()->optional(),
            ]),
            // biginteger
            $base->extend([
                'type' => z::literal('biginteger'),
                'required' => self::requiredSchema(),
                'unique' => self::uniqueSchema(),
                'default' => z::string()->regex('/^\d*$/')->nullish(),
                'min' => z::string()->regex('/^\d*$/')->nullish(),
                'max' => z::string()->regex('/^\d*$/')->nullish(),
            ]),
            // float
            $base->extend([
                'type' => z::literal('float'),
                'required' => self::requiredSchema(),
                'unique' => self::uniqueSchema(),
                'default' => z::number()->optional(),
                'min' => z::number()->optional(),
                'max' => z::number()->optional(),
            ]),
            // decimal
            $base->extend([
                'type' => z::literal('decimal'),
                'required' => self::requiredSchema(),
                'unique' => self::uniqueSchema(),
                'default' => z::number()->optional(),
                'min' => z::number()->optional(),
                'max' => z::number()->optional(),
            ]),
            $timeSchema,
            // date
            $timeSchema->extend(['type' => z::literal('date')]),
            // datetime
            $timeSchema->extend(['type' => z::literal('datetime')]),
            // timestamp
            $base->extend(['type' => z::literal('timestamp')]),
            // boolean
            $base->extend([
                'type' => z::literal('boolean'),
                'required' => self::requiredSchema(),
                'default' => z::boolean()->optional(),
            ]),
            // component
            $base->extend([
                'type' => z::literal('component'),
                'component' => z::string(),
                'repeatable' => z::boolean()->optional(),
                'required' => self::requiredSchema(),
                'min' => z::number()->optional(),
                'max' => z::number()->optional(),
            ]),
            // customField
            $base->extend([
                'type' => z::literal('customField'),
                'customField' => z::string(),
            ])->passthrough(),
        ];
    }

    private static function uidSchema(): ZodType
    {
        return self::basePropertiesSchema()->extend([
            'type' => z::literal('uid'),
            'targetField' => z::string()->nullish(),
            'required' => self::requiredSchema(),
            'default' => z::string()->nullish(),
            'minLength' => self::minLengthSchema(),
            'maxLength' => self::maxLengthSchema(),
            'options' => z::object([
                'separator' => z::string()->optional(),
                'lowercase' => z::boolean()->optional(),
                'decamelize' => z::boolean()->optional(),
                'customReplacements' => z::array(z::array(z::string())->length(2))->optional(),
                'preserveLeadingUnderscore' => z::boolean()->optional(),
            ])->optional(),
            'regex' => self::regexPatternSchema(),
        ]);
    }

    private static function dynamicZoneSchema(): ZodType
    {
        return self::basePropertiesSchema()->extend([
            'type' => z::literal('dynamiczone'),
            'components' => z::array(z::string())->nonempty(),
            'required' => self::requiredSchema(),
            'min' => z::number()->optional(),
            'max' => z::number()->optional(),
        ]);
    }

    /** @param SchemaMeta $meta */
    private static function attributePropertiesSchema(array $meta): ZodType
    {
        $relationSchema = self::createRelationSchema($meta);
        $options = [...self::baseAttributeSchemas(), $relationSchema];

        if ($meta['modelType'] === 'component') {
            return z::union($options)
                ->superRefine(self::enumRefinement(...))
                ->superRefine(self::checkUserTarget(...))
                ->superRefine(self::maxGreaterThanMin(...))
                ->superRefine(self::maxLengthGreaterThanMinLength(...));
        }

        return z::union([...$options, self::uidSchema(), self::dynamicZoneSchema()])
            ->superRefine(self::enumRefinement(...))
            ->superRefine(self::checkUserTarget(...))
            ->superRefine(self::uidRefinement(...))
            ->superRefine(self::maxGreaterThanMin(...))
            ->superRefine(self::maxLengthGreaterThanMinLength(...));
    }

    /** @param SchemaMeta $meta */
    private static function createAttributeSchema(array $meta): ZodObject
    {
        return z::object([
            'action' => z::literal('create'),
            'name' => z::string()
                ->regex(Common::NAME_REGEX)
                ->refine(static fn (mixed $value): bool => !Builder::isReservedAttributeName((string) $value), 'Attribute name is reserved'),
            'properties' => self::attributePropertiesSchema($meta),
        ]);
    }

    /** @param SchemaMeta $meta */
    private static function updateAttributeSchema(array $meta): ZodObject
    {
        return z::object([
            'action' => z::literal('update'),
            'name' => z::string(),
            'properties' => self::attributePropertiesSchema($meta),
        ]);
    }

    private static function deleteAttributeSchema(): ZodObject
    {
        return z::object([
            'action' => z::literal('delete'),
            'name' => z::string(),
        ]);
    }

    private static function nonEmptyStringUid(): ZodType
    {
        return z::custom(static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private static function categorySchema(): ZodType
    {
        return z::string()->min(1)->regex(Common::CATEGORY_NAME_REGEX);
    }

    private static function baseComponentSchema(): ZodObject
    {
        return z::object([
            'uid' => self::nonEmptyStringUid(),
            'displayName' => z::string()->min(1),
            'icon' => z::string()->regex(Common::ICON_REGEX)->optional(),
            'description' => z::string()->optional(),
            'category' => self::categorySchema(),
            'pluginOptions' => z::record(z::string(), z::unknown())->optional(),
        ]);
    }

    private static function baseContentTypeSchema(): ZodObject
    {
        return z::object([
            'uid' => self::nonEmptyStringUid(),
            'displayName' => z::string()->min(1),
            'description' => z::string()->optional(),
            'draftAndPublish' => z::boolean(),
            'options' => z::record(z::string(), z::unknown())->optional()->default([]),
            'pluginOptions' => z::record(z::string(), z::unknown())->optional()->default([]),
            'kind' => z::enum([Constants::TYPE_KINDS['SINGLE_TYPE'], Constants::TYPE_KINDS['COLLECTION_TYPE']])->optional(),
        ]);
    }

    private static function attributesArray(ZodType $item, bool $verifyUid): ZodType
    {
        $schema = z::array($item)->superRefine(self::uniqueAttributeName(...));

        return $verifyUid ? $schema->superRefine(self::verifyUidTargetField(...)) : $schema;
    }

    public static function schemaSchema(): ZodObject
    {
        $component = ['modelType' => 'component'];
        $singleType = ['modelType' => 'contentType', 'kind' => 'singleType'];
        $collectionType = ['modelType' => 'contentType', 'kind' => 'collectionType'];

        $createComponentSchema = self::baseComponentSchema()->extend([
            'action' => z::literal('create'),
            'config' => z::record(z::string(), z::unknown())->optional()->default([]),
            'attributes' => self::attributesArray(self::createAttributeSchema($component), false),
        ]);

        $updateComponentSchema = self::baseComponentSchema()->extend([
            'action' => z::literal('update'),
            'category' => self::categorySchema()->optional(),
            'attributes' => self::attributesArray(z::discriminatedUnion('action', [
                self::createAttributeSchema($component),
                self::updateAttributeSchema($component),
                self::deleteAttributeSchema(),
            ]), false),
        ]);

        $deleteComponentSchema = z::object([
            'action' => z::literal('delete'),
            'uid' => self::nonEmptyStringUid(),
        ]);

        $baseCreateContentTypeSchema = self::baseContentTypeSchema()->extend([
            'action' => z::literal('create'),
            'plugin' => z::string()->min(1)->optional(),
            'collectionName' => z::string()->regex(Common::COLLECTION_NAME_REGEX)->optional(),
            'singularName' => z::string()
                ->min(1)
                ->regex(Common::KEBAB_BASE_REGEX, 'Must be kebab case')
                ->refine(static fn (mixed $value): bool => !Builder::isReservedModelName((string) $value), 'Model name is reserved'),
            'pluralName' => z::string()
                ->min(1)
                ->regex(Common::KEBAB_BASE_REGEX, 'Must be kebab case')
                ->refine(static fn (mixed $value): bool => !Builder::isReservedModelName((string) $value), 'Model name is reserved'),
            'config' => z::record(z::string(), z::unknown())->optional(),
        ]);

        $createSingleTypeSchema = $baseCreateContentTypeSchema->extend([
            'kind' => z::literal(Constants::TYPE_KINDS['SINGLE_TYPE']),
            'attributes' => self::attributesArray(self::createAttributeSchema($singleType), true),
        ]);

        $createCollectionTypeSchema = $baseCreateContentTypeSchema->extend([
            'kind' => z::literal(Constants::TYPE_KINDS['COLLECTION_TYPE']),
            'attributes' => self::attributesArray(self::createAttributeSchema($collectionType), true),
        ]);

        $baseUpdateContentTypeSchema = self::baseContentTypeSchema()->extend([
            'action' => z::literal('update'),
        ]);

        $updateSingleTypeSchema = $baseUpdateContentTypeSchema->extend([
            'kind' => z::literal(Constants::TYPE_KINDS['SINGLE_TYPE'])->optional(),
            'attributes' => self::attributesArray(z::discriminatedUnion('action', [
                self::createAttributeSchema($singleType),
                self::updateAttributeSchema($singleType),
                self::deleteAttributeSchema(),
            ]), true),
        ]);

        $updateCollectionTypeSchema = $baseUpdateContentTypeSchema->extend([
            'kind' => z::literal(Constants::TYPE_KINDS['COLLECTION_TYPE'])->optional(),
            'attributes' => self::attributesArray(z::union([
                self::createAttributeSchema($collectionType),
                self::updateAttributeSchema($collectionType),
                self::deleteAttributeSchema(),
            ]), true),
        ]);

        $deleteContentTypeSchema = z::object([
            'action' => z::literal('delete'),
            'uid' => self::nonEmptyStringUid(),
        ]);

        return z::object([
            'components' => z::array(z::union([$createComponentSchema, $updateComponentSchema, $deleteComponentSchema]))
                ->optional()
                ->default([]),
            'contentTypes' => z::array(
                z::union([
                    $createSingleTypeSchema,
                    $createCollectionTypeSchema,
                    $updateSingleTypeSchema,
                    $updateCollectionTypeSchema,
                    $deleteContentTypeSchema,
                ])
                    ->superRefine(self::verifySingularAndPluralNames(...))
                    ->superRefine(self::verifyDraftAndPublishReservedAttributes(...)),
            )
                ->optional()
                ->default([]),
            'contentStructure' => ContentStructure::contentStructureFileSchema()->optional(),
        ]);
    }

    public static function updateSchemaInput(): ZodObject
    {
        return z::object(
            [
                'data' => self::schemaSchema(),
            ],
            [
                'error' => static fn (array $issue): string => ($issue['input'] ?? null) instanceof Undefined
                    ? 'Schema is required'
                    : 'Invalid schema, expected an object with a data property',
            ],
        );
    }

    public static function validateUpdateSchema(mixed $body): mixed
    {
        return z::validateZodSchema(self::updateSchemaInput())($body ?? Undefined::Value);
    }
}
