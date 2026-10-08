<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/services/uid.ts. */
final class Uid
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array{contentTypeUID: string, field: string, data: array<string, mixed>, locale?: string|null} $params */
    public function generateUIDField(array $params): string
    {
        ['contentTypeUID' => $contentTypeUID, 'field' => $field, 'data' => $data] = $params;
        $locale = $params['locale'] ?? null;

        $contentType = $this->strapi->contentTypes()[$contentTypeUID];
        $attributes = $contentType->attributes;

        $attribute = $attributes[$field];
        $targetField = $attribute['targetField'] ?? null;
        $defaultValue = $attribute['default'] ?? null;
        $options = is_array($attribute['options'] ?? null) ? $attribute['options'] : [];

        $targetValue = is_string($targetField) ? Objects::get($data, $targetField) : null;

        if (!Objects::isEmpty($targetValue)) {
            return $this->findUniqueUID([
                'contentTypeUID' => $contentTypeUID,
                'field' => $field,
                'value' => Strings::slugify(Strings::stringify($targetValue), $options),
                'locale' => $locale,
            ]);
        }

        $value = is_callable($defaultValue) && !is_string($defaultValue) ? $defaultValue() : $defaultValue;

        return $this->findUniqueUID([
            'contentTypeUID' => $contentTypeUID,
            'field' => $field,
            'value' => Strings::slugify(
                $value !== null && $value !== '' && $value !== false ? Strings::stringify($value) : $contentType->modelName,
                $options,
            ),
            'locale' => $locale,
        ]);
    }

    /** @param array{contentTypeUID: string, field: string, value: string, locale?: string|null} $params */
    public function findUniqueUID(array $params): string
    {
        ['contentTypeUID' => $contentTypeUID, 'field' => $field, 'value' => $value] = $params;

        $foundDocuments = $this->strapi->documents($contentTypeUID)->findMany([
            'filters' => [
                $field => ['$startsWith' => $value],
            ],
            ...self::localeParam($params['locale'] ?? null),
            // TODO: Check UX. When modifying an entry, it only makes sense to check for collisions with other drafts
            // However, when publishing this "available" UID might collide with another published entry
            'status' => 'draft',
        ]);

        if ($foundDocuments === []) {
            // If there are no documents found we can return the value as is
            return $value;
        }

        $possibleCollisions = array_map(static fn (array $doc): mixed => $doc[$field] ?? null, $foundDocuments);

        // If there are no documents sharing the proposed UID, we can return the value as is
        if (!in_array($value, $possibleCollisions, true)) {
            return $value;
        }

        $i = 1;
        $tmpUId = "{$value}-{$i}";
        while (in_array($tmpUId, $possibleCollisions, true)) {
            // While there are documents sharing the proposed UID, we need to find a new one
            // by incrementing the suffix until we find a unique one
            $i += 1;
            $tmpUId = "{$value}-{$i}";
        }

        return $tmpUId;
    }

    /** @param array{contentTypeUID: string, field: string, value: string, locale?: string|null} $params */
    public function checkUIDAvailability(array $params): bool
    {
        ['contentTypeUID' => $contentTypeUID, 'field' => $field, 'value' => $value] = $params;

        $documentCount = $this->strapi->documents($contentTypeUID)->count([
            'filters' => [
                $field => $value,
            ],
            ...self::localeParam($params['locale'] ?? null),
            // TODO: Check UX. When modifying an entry, it only makes sense to check for collisions with other drafts
            // However, when publishing this "available" UID might collide with another published entry
            'status' => 'draft',
        ]);

        if ($documentCount > 0) {
            // If there are documents sharing the proposed UID, we can return false
            return false;
        }

        return true;
    }

    /**
     * JS `locale: undefined` is an absent key.
     *
     * @return array{locale?: string}
     */
    private static function localeParam(?string $locale): array
    {
        return $locale !== null ? ['locale' => $locale] : [];
    }
}
