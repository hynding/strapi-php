<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of packages/core/utils/src/content-types.ts.
 *
 * Every helper accepts either a `Strapi\Types\Schema\Schema` or a plain schema array
 * (the `schema.json` shape with `attributes`, `options`, `kind`, `info`...). Pass through
 * {@see self::toArray()} to normalize.
 *
 * @phpstan-type Attribute array<string, mixed>
 * @phpstan-type Model array{uid?: string, modelType?: string, kind?: string, info?: array<string, mixed>, options?: array<string, mixed>, pluginOptions?: array<string, mixed>, attributes?: array<string, Attribute>, privateAttributes?: list<string>}
 * @phpstan-type ModelLike Schema|Model
 */
final class ContentTypes
{
    public const SINGLE_TYPE = 'singleType';
    public const COLLECTION_TYPE = 'collectionType';

    public const ID_ATTRIBUTE = 'id';
    public const DOC_ID_ATTRIBUTE = 'documentId';

    public const PUBLISHED_AT_ATTRIBUTE = 'publishedAt';
    public const FIRST_PUBLISHED_AT_ATTRIBUTE = 'firstPublishedAt';
    public const CREATED_BY_ATTRIBUTE = 'createdBy';
    public const UPDATED_BY_ATTRIBUTE = 'updatedBy';

    public const CREATED_AT_ATTRIBUTE = 'createdAt';
    public const UPDATED_AT_ATTRIBUTE = 'updatedAt';

    public const DP_PUB_STATE_LIVE = 'live';
    public const DP_PUB_STATE_PREVIEW = 'preview';
    public const DP_PUB_STATES = [self::DP_PUB_STATE_LIVE, self::DP_PUB_STATE_PREVIEW];

    /** Upstream `constants` export. */
    public const CONSTANTS = [
        'ID_ATTRIBUTE' => self::ID_ATTRIBUTE,
        'DOC_ID_ATTRIBUTE' => self::DOC_ID_ATTRIBUTE,
        'PUBLISHED_AT_ATTRIBUTE' => self::PUBLISHED_AT_ATTRIBUTE,
        'FIRST_PUBLISHED_AT_ATTRIBUTE' => self::FIRST_PUBLISHED_AT_ATTRIBUTE,
        'CREATED_BY_ATTRIBUTE' => self::CREATED_BY_ATTRIBUTE,
        'UPDATED_BY_ATTRIBUTE' => self::UPDATED_BY_ATTRIBUTE,
        'CREATED_AT_ATTRIBUTE' => self::CREATED_AT_ATTRIBUTE,
        'UPDATED_AT_ATTRIBUTE' => self::UPDATED_AT_ATTRIBUTE,
        'SINGLE_TYPE' => self::SINGLE_TYPE,
        'COLLECTION_TYPE' => self::COLLECTION_TYPE,
    ];

    /** ID-like fields accepted at root level and on relations/media/components. */
    public const ID_FIELDS = [self::ID_ATTRIBUTE, self::DOC_ID_ATTRIBUTE];
    /** Keys accepted on morphTo relation payloads. */
    public const MORPH_TO_KEYS = ['__type'];
    /** Keys accepted on dynamic zone component payloads. */
    public const DYNAMIC_ZONE_KEYS = ['__component'];
    /** Relation operation keys. */
    public const RELATION_OPERATION_KEYS = ['connect', 'disconnect', 'set', 'options'];

    public const RESERVED_ATTRIBUTE_NAMES = [
        'id',
        'document_id',
        'created_at',
        'updated_at',
        'published_at',
        'created_by_id',
        'updated_by_id',
        'created_by',
        'updated_by',
        'entry_id',
        'localizations',
        'meta',
        'locale',
        '__component',
        '__contentType',
        'strapi*',
        '_strapi*',
        '__strapi*',
    ];

    public const RESERVED_ATTRIBUTE_NAMES_DRAFT_PUBLISH = ['status'];

    public const RESERVED_MODEL_NAMES = [
        'boolean',
        'date',
        'date_time',
        'time',
        'upload',
        'document',
        'then',
        'strapi*',
        '_strapi*',
        '__strapi*',
    ];

    private const HAS_RELATION_REORDERING = ['manyToMany', 'manyToOne', 'oneToMany'];

    /**
     * Private attributes configured globally (`config/api.php` → responses.privateAttributes).
     * The core package sets this at boot; upstream reads `strapi.config.get('api.responses.privateAttributes')`.
     *
     * @var list<string>
     */
    private static array $globalPrivateAttributes = [];

    /** @param list<string> $attributes */
    public static function setGlobalPrivateAttributes(array $attributes): void
    {
        self::$globalPrivateAttributes = array_values($attributes);
    }

    /** @return list<string> */
    public static function getGlobalPrivateAttributes(): array
    {
        return self::$globalPrivateAttributes;
    }

    /**
     * Normalize a Schema object or array into the plain array shape.
     *
     * @param ModelLike|null $model
     * @return Model
     */
    public static function toArray(Schema|array|null $model): array
    {
        if ($model === null) {
            return ['attributes' => []];
        }
        if ($model instanceof Schema) {
            /** @var Model $array */
            $array = $model->toArray();

            return $array;
        }
        if (!isset($model['attributes'])) {
            $model['attributes'] = [];
        }

        return $model;
    }

    /**
     * @param ModelLike|null $model
     * @return array<string, Attribute>
     */
    public static function attributes(Schema|array|null $model): array
    {
        if ($model instanceof Schema) {
            return $model->attributes;
        }

        return $model['attributes'] ?? [];
    }

    /**
     * @param ModelLike|null $model
     * @return Attribute|null
     */
    public static function attribute(Schema|array|null $model, string $name): ?array
    {
        $attribute = self::attributes($model)[$name] ?? null;

        return is_array($attribute) ? $attribute : null;
    }

    /** @param ModelLike|null $model */
    public static function uid(Schema|array|null $model): ?string
    {
        if ($model instanceof Schema) {
            return $model->uid;
        }
        $uid = $model['uid'] ?? null;

        return is_string($uid) ? $uid : null;
    }

    /**
     * @param ModelLike|null $model
     * @return array<string, mixed>
     */
    public static function options(Schema|array|null $model): array
    {
        if ($model instanceof Schema) {
            return $model->options;
        }
        $options = $model['options'] ?? [];

        return is_array($options) ? $options : [];
    }

    /**
     * @param ModelLike|null $model
     * @return array<string, mixed>
     */
    public static function info(Schema|array|null $model): array
    {
        if ($model instanceof Schema) {
            return $model->info;
        }
        $info = $model['info'] ?? [];

        return is_array($info) ? $info : [];
    }

    /** @param list<string> $list */
    private static function matchesReservedName(string $snakeCaseName, array $list): bool
    {
        if (in_array($snakeCaseName, $list, true)) {
            return true;
        }

        foreach ($list as $entry) {
            if (str_ends_with($entry, '*') && str_starts_with($snakeCaseName, substr($entry, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public static function isReservedAttributeName(string $name, bool $draftAndPublish = true): bool
    {
        $snakeCaseName = Strings::snakeCase($name);

        if (self::matchesReservedName($snakeCaseName, self::RESERVED_ATTRIBUTE_NAMES)) {
            return true;
        }

        return $draftAndPublish && self::matchesReservedName($snakeCaseName, self::RESERVED_ATTRIBUTE_NAMES_DRAFT_PUBLISH);
    }

    public static function isReservedModelName(string $name): bool
    {
        return self::matchesReservedName(Strings::snakeCase($name), self::RESERVED_MODEL_NAMES);
    }

    /** @return list<string> */
    public static function getReservedAttributeNames(bool $draftAndPublish = true): array
    {
        return $draftAndPublish
            ? [...self::RESERVED_ATTRIBUTE_NAMES, ...self::RESERVED_ATTRIBUTE_NAMES_DRAFT_PUBLISH]
            : self::RESERVED_ATTRIBUTE_NAMES;
    }

    /** @return list<string> */
    public static function getReservedModelNames(): array
    {
        return self::RESERVED_MODEL_NAMES;
    }

    /**
     * @param iterable<string> $attributeNames
     * @return list<string>
     */
    public static function findDraftAndPublishReservedAttributeNames(iterable $attributeNames): array
    {
        $out = [];
        foreach ($attributeNames as $name) {
            if (self::matchesReservedName(Strings::snakeCase($name), self::RESERVED_ATTRIBUTE_NAMES_DRAFT_PUBLISH)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public static function getDraftAndPublishReservedAttributeWarning(string $uid, string $attributeName): string
    {
        return "The attribute name '{$attributeName}' on content type '{$uid}' is reserved when 'draftAndPublish' is enabled. It conflicts with the Document Service / REST 'status' query parameter. Rename the attribute or disable the 'draftAndPublish' option.";
    }

    /** @param list<string> $attributeNames */
    public static function getDraftAndPublishEnableBlockedMessage(array $attributeNames): string
    {
        return 'Cannot enable draft and publish while the following attribute names are reserved: ' . implode(', ', $attributeNames) . '. Rename them or remove them first.';
    }

    /**
     * @param ModelLike $model
     * @return list<string>
     */
    public static function getTimestamps(Schema|array $model): array
    {
        $attributes = self::attributes($model);
        $out = [];

        if (array_key_exists(self::CREATED_AT_ATTRIBUTE, $attributes)) {
            $out[] = self::CREATED_AT_ATTRIBUTE;
        }
        if (array_key_exists(self::UPDATED_AT_ATTRIBUTE, $attributes)) {
            $out[] = self::UPDATED_AT_ATTRIBUTE;
        }

        return $out;
    }

    /**
     * @param ModelLike $model
     * @return list<string>
     */
    public static function getCreatorFields(Schema|array $model): array
    {
        $attributes = self::attributes($model);
        $out = [];

        if (array_key_exists(self::CREATED_BY_ATTRIBUTE, $attributes)) {
            $out[] = self::CREATED_BY_ATTRIBUTE;
        }
        if (array_key_exists(self::UPDATED_BY_ATTRIBUTE, $attributes)) {
            $out[] = self::UPDATED_BY_ATTRIBUTE;
        }

        return $out;
    }

    /**
     * @param ModelLike|null $model
     * @return list<string>
     */
    public static function getNonWritableAttributes(Schema|array|null $model): array
    {
        if ($model === null) {
            return [];
        }

        $nonWritable = [];
        foreach (self::attributes($model) as $name => $attr) {
            if (($attr['writable'] ?? null) === false) {
                $nonWritable[] = $name;
            }
        }

        return array_values(array_unique([
            self::ID_ATTRIBUTE,
            self::DOC_ID_ATTRIBUTE,
            ...self::getTimestamps($model),
            ...$nonWritable,
        ]));
    }

    /**
     * @param ModelLike|null $model
     * @return list<string>
     */
    public static function getWritableAttributes(Schema|array|null $model): array
    {
        if ($model === null) {
            return [];
        }

        return array_values(array_diff(array_keys(self::attributes($model)), self::getNonWritableAttributes($model)));
    }

    /** @param ModelLike $model */
    public static function isWritableAttribute(Schema|array $model, string $attributeName): bool
    {
        return in_array($attributeName, self::getWritableAttributes($model), true);
    }

    /**
     * @param ModelLike $model
     * @return list<string>
     */
    public static function getNonVisibleAttributes(Schema|array $model): array
    {
        $nonVisible = [];
        foreach (self::attributes($model) as $name => $attr) {
            if (($attr['visible'] ?? null) === false) {
                $nonVisible[] = $name;
            }
        }

        return array_values(array_unique([
            self::ID_ATTRIBUTE,
            self::DOC_ID_ATTRIBUTE,
            self::PUBLISHED_AT_ATTRIBUTE,
            ...self::getTimestamps($model),
            ...$nonVisible,
        ]));
    }

    /**
     * @param ModelLike $model
     * @return list<string>
     */
    public static function getVisibleAttributes(Schema|array $model): array
    {
        return array_values(array_diff(array_keys(self::attributes($model)), self::getNonVisibleAttributes($model)));
    }

    /** @param ModelLike $model */
    public static function isVisibleAttribute(Schema|array $model, string $attributeName): bool
    {
        return in_array($attributeName, self::getVisibleAttributes($model), true);
    }

    /**
     * @param ModelLike $model
     * @return array<string, mixed>
     */
    public static function getOptions(Schema|array $model): array
    {
        return array_merge(['draftAndPublish' => false], self::options($model));
    }

    /** @param ModelLike|null $model */
    public static function hasDraftAndPublish(Schema|array|null $model): bool
    {
        return (self::options($model)['draftAndPublish'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $data
     * @param ModelLike $model
     */
    public static function isDraft(array $data, Schema|array $model): bool
    {
        return self::hasDraftAndPublish($model) && array_key_exists(self::PUBLISHED_AT_ATTRIBUTE, $data) && $data[self::PUBLISHED_AT_ATTRIBUTE] === null;
    }

    public static function isSchema(mixed $data): bool
    {
        if ($data instanceof Schema) {
            return true;
        }

        return is_array($data) && in_array($data['modelType'] ?? null, ['component', 'contentType'], true);
    }

    public static function isComponentSchema(mixed $data): bool
    {
        if ($data instanceof Schema) {
            return $data->isComponent();
        }

        return is_array($data) && ($data['modelType'] ?? null) === 'component';
    }

    public static function isContentTypeSchema(mixed $data): bool
    {
        if ($data instanceof Schema) {
            return !$data->isComponent();
        }

        return is_array($data) && ($data['modelType'] ?? null) === 'contentType';
    }

    /** @param ModelLike $model */
    public static function isSingleType(Schema|array $model): bool
    {
        return self::kind($model) === self::SINGLE_TYPE;
    }

    /** @param ModelLike $model */
    public static function isCollectionType(Schema|array $model): bool
    {
        return self::kind($model) === self::COLLECTION_TYPE;
    }

    /** @param ModelLike $model */
    private static function kind(Schema|array $model): string
    {
        if ($model instanceof Schema) {
            return $model->kind ?? self::COLLECTION_TYPE;
        }

        $kind = $model['kind'] ?? null;

        return is_string($kind) ? $kind : self::COLLECTION_TYPE;
    }

    /** @return callable(ModelLike): bool */
    public static function isKind(string $kind): callable
    {
        return static fn (Schema|array $model): bool => ($model instanceof Schema ? $model->kind : ($model['kind'] ?? null)) === $kind;
    }

    /**
     * Private attributes declared on the model options or globally (not the ones flagged `private: true`).
     *
     * @param ModelLike|null $model
     * @return list<string>
     */
    public static function getStoredPrivateAttributes(Schema|array|null $model): array
    {
        $fromOptions = self::options($model)['privateAttributes'] ?? [];
        $fromModel = is_array($model) ? ($model['privateAttributes'] ?? []) : [];

        /** @var list<string> $all */
        $all = array_values(array_unique([...(is_array($fromOptions) ? $fromOptions : []), ...(is_array($fromModel) ? $fromModel : []), ...self::$globalPrivateAttributes]));

        return $all;
    }

    /**
     * @param ModelLike $model
     * @return list<string>
     */
    public static function getPrivateAttributes(Schema|array $model): array
    {
        $flagged = [];
        foreach (self::attributes($model) as $name => $attr) {
            if (($attr['private'] ?? false) === true) {
                $flagged[] = $name;
            }
        }

        return array_values(array_unique([...self::getStoredPrivateAttributes($model), ...$flagged]));
    }

    /** @param ModelLike|null $model */
    public static function isPrivateAttribute(Schema|array|null $model, string $attributeName): bool
    {
        if ((self::attribute($model, $attributeName)['private'] ?? null) === true) {
            return true;
        }

        return in_array($attributeName, self::getStoredPrivateAttributes($model), true);
    }

    /** @param Attribute|null $attribute */
    public static function isScalarAttribute(?array $attribute): bool
    {
        return $attribute !== null && !in_array($attribute['type'] ?? null, ['media', 'component', 'relation', 'dynamiczone'], true);
    }

    /** @param Attribute $attribute */
    public static function getDoesAttributeRequireValidation(array $attribute): bool
    {
        return (bool) ($attribute['required'] ?? false)
            || (bool) ($attribute['unique'] ?? false)
            || array_key_exists('max', $attribute)
            || array_key_exists('min', $attribute)
            || array_key_exists('maxLength', $attribute)
            || array_key_exists('minLength', $attribute);
    }

    /** @param Attribute|null $attribute */
    public static function isMediaAttribute(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'media';
    }

    /** @param Attribute|null $attribute */
    public static function isRelationalAttribute(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'relation';
    }

    /** @param Attribute|null $attribute */
    public static function hasRelationReordering(?array $attribute): bool
    {
        return self::isRelationalAttribute($attribute) && in_array($attribute['relation'] ?? null, self::HAS_RELATION_REORDERING, true);
    }

    /** @param Attribute|null $attribute */
    public static function isComponentAttribute(?array $attribute): bool
    {
        return $attribute !== null && in_array($attribute['type'] ?? null, ['component', 'dynamiczone'], true);
    }

    /** @param Attribute|null $attribute */
    public static function isDynamicZoneAttribute(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'dynamiczone';
    }

    /** @param Attribute|null $attribute */
    public static function isMorphToRelationalAttribute(?array $attribute): bool
    {
        if (!self::isRelationalAttribute($attribute)) {
            return false;
        }
        $relation = $attribute['relation'] ?? null;

        return is_string($relation) && str_starts_with($relation, 'morphTo');
    }

    /** @param Attribute|null $attribute */
    public static function isMorphRelationalAttribute(?array $attribute): bool
    {
        if (!self::isRelationalAttribute($attribute)) {
            return false;
        }
        $relation = $attribute['relation'] ?? null;

        return is_string($relation) && str_starts_with(strtolower($relation), 'morph');
    }

    /**
     * @param ModelLike $schema
     * @return list<string>
     */
    public static function getComponentAttributes(Schema|array $schema): array
    {
        return self::attributeNamesWhere($schema, self::isComponentAttribute(...));
    }

    /**
     * @param ModelLike $schema
     * @return list<string>
     */
    public static function getMediaAttributes(Schema|array $schema): array
    {
        return self::attributeNamesWhere($schema, self::isMediaAttribute(...));
    }

    /**
     * @param ModelLike $schema
     * @return list<string>
     */
    public static function getScalarAttributes(Schema|array $schema): array
    {
        return self::attributeNamesWhere($schema, self::isScalarAttribute(...));
    }

    /**
     * @param ModelLike $schema
     * @return list<string>
     */
    public static function getRelationalAttributes(Schema|array $schema): array
    {
        return self::attributeNamesWhere($schema, self::isRelationalAttribute(...));
    }

    /**
     * @param ModelLike $schema
     * @param callable(Attribute): bool $predicate
     * @return list<string>
     */
    private static function attributeNamesWhere(Schema|array $schema, callable $predicate): array
    {
        $out = [];
        foreach (self::attributes($schema) as $name => $attr) {
            if ($predicate($attr)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @param Attribute|null $attribute */
    public static function isTypedAttribute(?array $attribute, string $type): bool
    {
        return $attribute !== null && array_key_exists('type', $attribute) && $attribute['type'] === $type;
    }

    /** @param ModelLike $contentType */
    public static function getContentTypeRoutePrefix(Schema|array $contentType): string
    {
        $info = self::info($contentType);
        $name = self::isSingleType($contentType) ? ($info['singularName'] ?? '') : ($info['pluralName'] ?? '');

        return Strings::kebabCase(is_string($name) ? $name : '');
    }

    /**
     * `getDoesPluginOptionHaveValue(model, 'i18n', 'localized', true)`.
     *
     * @param ModelLike $model
     */
    public static function getDoesPluginOptionHaveValue(Schema|array $model, string $plugin, string $option, mixed $value = true): bool
    {
        $pluginOptions = $model instanceof Schema ? $model->pluginOptions : ($model['pluginOptions'] ?? []);

        return is_array($pluginOptions) && (($pluginOptions[$plugin][$option] ?? null) === $value);
    }
}
