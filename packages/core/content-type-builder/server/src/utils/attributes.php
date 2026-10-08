<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Utils;

use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/utils/attributes.ts.
 *
 * JavaScript `undefined` is a missing key here: a property upstream sets to `undefined` (and
 * `JSON.stringify` then drops) is left out or removed.
 */
final class Attributes
{
    /**
     * `_.get(attribute, 'configurable', true)`, as a truth value.
     *
     * @param array<string, mixed> $attribute
     */
    public static function isConfigurable(array $attribute): bool
    {
        return array_key_exists('configurable', $attribute) ? self::truthy($attribute['configurable']) : true;
    }

    /** @param array<string, mixed> $attribute */
    public static function isRelation(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'relation';
    }

    /**
     * Formats a component's attributes (only the attributes the CTB can see).
     *
     * @param Schema|array<string, mixed> $model
     * @return array<string, array<string, mixed>>
     */
    public static function formatAttributes(Schema|array $model): array
    {
        $attributes = ContentTypes::attributes($model);
        $out = [];
        foreach (ContentTypes::getVisibleAttributes($model) as $key) {
            $out[$key] = self::formatAttribute($attributes[$key]);
        }

        return $out;
    }

    /**
     * Formats a component attribute.
     *
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    public static function formatAttribute(array $attribute): array
    {
        if (($attribute['type'] ?? null) === 'media') {
            $out = [
                'type' => 'media',
                'multiple' => self::truthy($attribute['multiple'] ?? null),
                'required' => self::truthy($attribute['required'] ?? null),
            ];
            if (($attribute['configurable'] ?? null) === false) {
                $out['configurable'] = false;
            }
            $out['private'] = self::truthy($attribute['private'] ?? null);
            if (array_key_exists('allowedTypes', $attribute)) {
                $out['allowedTypes'] = $attribute['allowedTypes'];
            }
            if (array_key_exists('pluginOptions', $attribute)) {
                $out['pluginOptions'] = $attribute['pluginOptions'];
            }

            return $out;
        }

        if (($attribute['type'] ?? null) === 'relation') {
            $out = $attribute;
            $out['type'] = 'relation';
            $inversedBy = $attribute['inversedBy'] ?? null;
            $mappedBy = $attribute['mappedBy'] ?? null;
            $out['targetAttribute'] = self::truthy($inversedBy) ? $inversedBy : (self::truthy($mappedBy) ? $mappedBy : null);
            if (($attribute['configurable'] ?? null) === false) {
                $out['configurable'] = false;
            } else {
                unset($out['configurable']);
            }
            $out['required'] = self::truthy($attribute['required'] ?? null);
            $out['private'] = self::truthy($attribute['private'] ?? null);

            return $out;
        }

        return $attribute;
    }

    /**
     * `replaceTemporaryUIDs(uidMap)(schema)`: component attributes pointing at a temporary uid
     * (components created in the same request) get the final uid.
     *
     * @param array<string, string> $uidMap
     * @return \Closure(array<string, mixed>): array<string, mixed>
     */
    public static function replaceTemporaryUIDs(array $uidMap, ?Strapi $strapi = null): \Closure
    {
        return static function (array $schema) use ($uidMap, $strapi): array {
            $components = ($strapi ?? Core::instance())?->components() ?? [];
            $attributes = [];

            foreach ($schema['attributes'] ?? [] as $key => $attr) {
                $type = is_array($attr) ? ($attr['type'] ?? null) : null;

                if ($type === 'component') {
                    $component = $attr['component'] ?? null;
                    if (is_string($component) && array_key_exists($component, $uidMap)) {
                        $attributes[$key] = [...$attr, 'component' => $uidMap[$component]];
                        continue;
                    }

                    if (!is_string($component) || !array_key_exists($component, $components)) {
                        throw new ApplicationError('component.notFound');
                    }
                }

                if (
                    $type === 'dynamiczone'
                    && is_array($attr['components'] ?? null)
                    && array_intersect($attr['components'], array_keys($uidMap)) !== []
                ) {
                    $attributes[$key] = [
                        ...$attr,
                        'components' => array_map(static function (mixed $value) use ($uidMap, $components): mixed {
                            if (is_string($value) && array_key_exists($value, $uidMap)) {
                                return $uidMap[$value];
                            }

                            if (!is_string($value) || !array_key_exists($value, $components)) {
                                throw new ApplicationError('component.notFound');
                            }

                            return $value;
                        }, $attr['components']),
                    ];
                    continue;
                }

                $attributes[$key] = $attr;
            }

            return [...$schema, 'attributes' => $attributes];
        };
    }

    /** JavaScript truthiness (`!!value`): arrays (objects) are always truthy. */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false, $value === '', $value === 0 => false,
            is_float($value) => $value !== 0.0 && !is_nan($value),
            is_string($value) => true,
            is_array($value), is_object($value) => true,
            default => (bool) $value,
        };
    }
}
