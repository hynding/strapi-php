<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/services/data-mapper.ts.
 *
 * A content-manager model is the plain schema array (`Schema::toArray()` plus a user `config`)
 * with `apiID`, `isDisplayed` and formatted attributes. The PHP-only keys core keeps in a schema's
 * `config` (`__schema__`, `actions`, `lifecycles`, `indexes`, `foreignKeys`) are left out: upstream
 * keeps them elsewhere and the model configuration validator rejects unknown keys.
 */
final class DataMapper
{
    private const array DTO_FIELDS = [
        'uid',
        'isDisplayed',
        'apiID',
        'kind',
        'category',
        'info',
        'options',
        'pluginOptions',
        'attributes',
    ];

    private const array INTERNAL_CONFIG_KEYS = ['__schema__', '__filename__', 'actions', 'lifecycles', 'indexes', 'foreignKeys'];

    /** The factory receives the Strapi instance like every service; the mapper does not need it. */
    public function __construct(?Strapi $strapi = null)
    {
        unset($strapi);
    }

    /**
     * @param Schema|array<string, mixed> $contentType
     * @return array<string, mixed>
     */
    public function toContentManagerModel(Schema|array $contentType): array
    {
        $model = self::toPlainSchema($contentType);

        return [
            ...$model,
            'apiID' => $model['modelName'] ?? null,
            'isDisplayed' => self::isVisible($model),
            'attributes' => [
                'id' => [
                    'type' => 'integer',
                ],
                ...self::formatAttributes($model),
                'documentId' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    /**
     * `pick(dtoFields)`; empty `info` / `options` / `pluginOptions` stay JSON objects.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    public function toDto(array $model): array
    {
        $dto = Objects::pick($model, self::DTO_FIELDS);
        foreach (['info', 'options', 'pluginOptions'] as $key) {
            if (array_key_exists($key, $dto) && $dto[$key] === []) {
                $dto[$key] = new \stdClass();
            }
        }

        return $dto;
    }

    /**
     * @param Schema|array<string, mixed> $contentType
     * @return array<string, mixed>
     */
    private static function toPlainSchema(Schema|array $contentType): array
    {
        if (is_array($contentType)) {
            return $contentType;
        }

        $model = $contentType->toArray();
        $config = array_diff_key($contentType->config, array_flip(self::INTERNAL_CONFIG_KEYS));
        if ($config !== []) {
            $model['config'] = $config;
        }

        return $model;
    }

    /**
     * only get attributes that can be seen in the auto generated Edit view or List view
     *
     * @param array<string, mixed> $contentType
     * @return array<string, mixed>
     */
    private static function formatAttributes(array $contentType): array
    {
        $keys = [
            ...ContentTypes::getVisibleAttributes($contentType),
            ...ContentTypes::getTimestamps($contentType),
            ...ContentTypes::getCreatorFields($contentType),
        ];

        $acc = [];
        foreach ($keys as $key) {
            $attribute = $contentType['attributes'][$key];

            // ignore morph until they are handled in the front
            if (($attribute['type'] ?? null) === 'relation' && str_contains(strtolower((string) ($attribute['relation'] ?? '')), 'morph')) {
                continue;
            }

            $acc[$key] = self::formatAttribute($attribute);
        }

        return $acc;
    }

    /**
     * FIXME: not needed
     *
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    private static function formatAttribute(array $attribute): array
    {
        if (($attribute['type'] ?? null) === 'relation') {
            return self::toRelation($attribute);
        }

        return $attribute;
    }

    /**
     * FIXME: not needed
     *
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    private static function toRelation(array $attribute): array
    {
        $relation = [
            ...$attribute,
            'type' => 'relation',
        ];
        if (array_key_exists('target', $attribute)) {
            $relation['targetModel'] = $attribute['target'];
        }
        $relation['relationType'] = $attribute['relation'] ?? null;

        return $relation;
    }

    /** @param array<string, mixed> $model */
    private static function isVisible(array $model): bool
    {
        $visible = $model['pluginOptions']['content-manager']['visible'] ?? true;

        return $visible === true;
    }
}
