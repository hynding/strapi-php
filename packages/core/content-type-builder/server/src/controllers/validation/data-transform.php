<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Utils\Typeguards;

/**
 * Port of server/src/controllers/validation/data-transform.ts. Upstream mutates the payload in
 * place; PHP arrays are values, so the transformed payload is returned.
 */
final class DataTransform
{
    /**
     * `attribute.default = undefined` for every attribute whose default is an empty string.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>|null
     */
    public static function removeEmptyDefaults(?array $data): ?array
    {
        if (!is_array($data['attributes'] ?? null)) {
            return $data;
        }

        foreach ($data['attributes'] as $attributeName => $attribute) {
            if (is_array($attribute) && Typeguards::hasDefaultAttribute($attribute) && $attribute['default'] === '') {
                unset($data['attributes'][$attributeName]['default']);
            }
        }

        return $data;
    }

    /**
     * `attribute.targetField = undefined` for uid attributes targeting a removed attribute.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function removeDeletedUIDTargetFields(array $data): array
    {
        if (array_key_exists('attributes', $data) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $name => $attribute) {
                if (
                    is_array($attribute)
                    && ($attribute['type'] ?? null) === 'uid'
                    && array_key_exists('targetField', $attribute)
                    && !(is_string($attribute['targetField']) && array_key_exists($attribute['targetField'], $data['attributes']))
                ) {
                    unset($data['attributes'][$name]['targetField']);
                }
            }
        }

        return $data;
    }
}
