<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes as ContentTypeUtils;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/content-types.ts.
 *
 * Models are `Schema` objects or their array shape (the stored `oldContentTypes` of a sync).
 */
final class ContentTypes
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param Schema|array<string, mixed>|null $modelOrAttribute */
    private static function hasLocalizedOption(Schema|array|null $modelOrAttribute): bool
    {
        if ($modelOrAttribute instanceof Schema) {
            return ($modelOrAttribute->pluginOptions['i18n']['localized'] ?? null) === true;
        }

        return is_array($modelOrAttribute) && ($modelOrAttribute['pluginOptions']['i18n']['localized'] ?? null) === true;
    }

    public function getValidLocale(mixed $locale): mixed
    {
        $localesService = Utils::locales($this->strapi);

        if ($locale === null) {
            return $localesService->getDefaultLocale();
        }

        $foundLocale = $localesService->findByCode($locale);
        if ($foundLocale === null) {
            throw new ApplicationError('Locale not found');
        }

        return $locale;
    }

    /**
     * Returns whether an attribute is localized or not
     *
     * @param array<string, mixed>|null $attribute
     */
    public static function isLocalizedAttribute(?array $attribute): bool
    {
        return self::hasLocalizedOption($attribute)
            || ContentTypeUtils::isRelationalAttribute($attribute)
            || ContentTypeUtils::isTypedAttribute($attribute, 'uid');
    }

    /**
     * Returns whether a model is localized or not
     *
     * @param Schema|array<string, mixed>|null $model
     */
    public function isLocalizedContentType(Schema|array|null $model): bool
    {
        return self::hasLocalizedOption($model);
    }

    /**
     * Returns the list of attribute names that are not localized
     *
     * @param Schema|array<string, mixed> $model
     * @return list<string>
     */
    public function getNonLocalizedAttributes(Schema|array $model): array
    {
        $attributes = ContentTypeUtils::attributes($model);

        return array_values(array_filter(
            ContentTypeUtils::getVisibleAttributes($model),
            static fn (string $attrName): bool => !self::isLocalizedAttribute($attributes[$attrName] ?? null),
        ));
    }

    /**
     * @param Schema|array<string, mixed>|null $model
     * @return array<string, mixed>|null
     */
    private function removeIdsMut(Schema|array|null $model, mixed $entry): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }

        // removeId
        unset($entry['id']);

        foreach (ContentTypeUtils::attributes($model) as $attrName => $attr) {
            if (!array_key_exists($attrName, $entry)) {
                continue;
            }
            $value = $entry[$attrName];
            $type = $attr['type'] ?? null;
            if ($type === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                foreach ($value as $index => $compo) {
                    if (is_array($compo) && array_key_exists('__component', $compo)) {
                        $value[$index] = $this->removeIdsMut($this->strapi->components()[(string) $compo['__component']] ?? null, $compo);
                    }
                }
                $entry[$attrName] = $value;
            } elseif ($type === 'component') {
                $componentModel = $this->strapi->components()[(string) ($attr['component'] ?? '')] ?? null;
                if (is_array($value) && array_is_list($value)) {
                    $entry[$attrName] = array_map(fn (mixed $compo): mixed => $this->removeIdsMut($componentModel, $compo), $value);
                } else {
                    $entry[$attrName] = $this->removeIdsMut($componentModel, $value);
                }
            }
        }

        return $entry;
    }

    /**
     * Returns a copy of an entry picking only its non localized attributes
     *
     * @param Schema|array<string, mixed> $model
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>
     */
    public function copyNonLocalizedAttributes(Schema|array $model, ?array $entry): array
    {
        $nonLocalizedAttributes = $this->getNonLocalizedAttributes($model);

        // pick() on a nil entry is {}
        $picked = [];
        foreach ($nonLocalizedAttributes as $name) {
            if (is_array($entry) && array_key_exists($name, $entry)) {
                $picked[$name] = $entry[$name];
            }
        }

        $result = $this->removeIdsMut($model, $picked);

        return is_array($result) ? $result : [];
    }

    /**
     * Returns the list of attribute names that are localized
     *
     * @param Schema|array<string, mixed> $model
     * @return list<string>
     */
    public function getLocalizedAttributes(Schema|array $model): array
    {
        $attributes = ContentTypeUtils::attributes($model);

        return array_values(array_filter(
            ContentTypeUtils::getVisibleAttributes($model),
            static fn (string $attrName): bool => self::isLocalizedAttribute($attributes[$attrName] ?? null),
        ));
    }

    /**
     * Fill non localized fields of an entry if there are nil
     *
     * @param array<string, mixed> $entry entry to fill
     * @param array<string, mixed>|null $relatedEntry values used to fill
     * @param array{model: string} $options
     */
    public function fillNonLocalizedAttributes(array &$entry, ?array $relatedEntry, array $options): void
    {
        if ($relatedEntry === null) {
            return;
        }

        $modelDef = $this->strapi->getModel($options['model']);
        if ($modelDef === null) {
            return;
        }
        $relatedEntryCopy = $this->copyNonLocalizedAttributes($modelDef, $relatedEntry);

        foreach ($relatedEntryCopy as $field => $value) {
            if (($entry[$field] ?? null) === null) {
                $entry[$field] = $value;
            }
        }
    }

    /**
     * build the populate param to
     *
     * @param string $modelUID uid of the model, could be of a content-type or a component
     * @return list<string>
     */
    public function getNestedPopulateOfNonLocalizedAttributes(string $modelUID): array
    {
        $schema = $this->strapi->getModel($modelUID);
        if ($schema === null) {
            throw new \RuntimeException("Cannot read properties of undefined (model {$modelUID})");
        }
        $scalarAttributes = ContentTypeUtils::getScalarAttributes($schema);
        $nonLocalizedAttributes = $this->getNonLocalizedAttributes($schema);

        $allAttributes = [...$scalarAttributes, ...$nonLocalizedAttributes];
        if ($schema->modelType === 'component') {
            // When called recursively on a non localized component we
            // need to explicitly populate that components relations
            array_push($allAttributes, ...ContentTypeUtils::getRelationalAttributes($schema));
        }

        // keep only the values that appear exactly once
        $counts = array_count_values($allAttributes);
        $currentAttributesToPopulate = array_values(array_filter($allAttributes, static fn (string $value): bool => $counts[$value] === 1));

        $attributesToPopulate = $currentAttributesToPopulate;
        foreach ($currentAttributesToPopulate as $attrName) {
            $attr = $schema->attributes[$attrName] ?? [];
            if (($attr['type'] ?? null) === 'component') {
                foreach ($this->getNestedPopulateOfNonLocalizedAttributes((string) $attr['component']) as $nestedAttr) {
                    $attributesToPopulate[] = "{$attrName}.{$nestedAttr}";
                }
            } elseif (($attr['type'] ?? null) === 'dynamiczone') {
                foreach ((array) ($attr['components'] ?? []) as $componentName) {
                    foreach ($this->getNestedPopulateOfNonLocalizedAttributes((string) $componentName) as $nestedAttr) {
                        $attributesToPopulate[] = "{$attrName}.{$nestedAttr}";
                    }
                }
            }
        }

        return $attributesToPopulate;
    }
}
