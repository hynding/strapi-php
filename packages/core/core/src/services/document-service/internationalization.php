<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\ValidationError;

/** Port of services/document-service/internationalization.ts: the locale params transforms. */
final class Internationalization
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function defaultLocale(Strapi $strapi, Schema $contentType, array $params): array
    {
        if (!$strapi->localization()->isLocalizedContentType($contentType)) {
            return $params;
        }

        if (empty($params['locale'])) {
            return [...$params, 'locale' => $strapi->localization()->getDefaultLocale()];
        }

        return $params;
    }

    /**
     * Add locale lookup query to the params.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function localeToLookup(Strapi $strapi, Schema $contentType, array $params): array
    {
        if (empty($params['locale']) || !$strapi->localization()->isLocalizedContentType($contentType)) {
            return $params;
        }

        if (!is_string($params['locale'])) {
            // localeToLookup accepts locales of '*'
            $printed = is_array($params['locale']) ? implode(',', array_map('strval', $params['locale'])) : (string) $params['locale'];

            throw new ValidationError("Invalid locale param {$printed} provided. Document locales must be strings.");
        }

        return [...$params, 'lookup' => [...(is_array($params['lookup'] ?? null) ? $params['lookup'] : []), 'locale' => $params['locale']]];
    }

    /**
     * Add locale lookup query to the params (accepting a list / '*').
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function multiLocaleToLookup(Strapi $strapi, Schema $contentType, array $params): array
    {
        if (!$strapi->localization()->isLocalizedContentType($contentType)) {
            return $params;
        }

        if (!empty($params['locale'])) {
            if ($params['locale'] === '*') {
                return $params;
            }

            return [...$params, 'lookup' => [...(is_array($params['lookup'] ?? null) ? $params['lookup'] : []), 'locale' => $params['locale']]];
        }

        return $params;
    }

    /**
     * Translate locale parameter into the data that will be saved.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function localeToData(Strapi $strapi, Schema $contentType, array $params): array
    {
        if (!$strapi->localization()->isLocalizedContentType($contentType)) {
            return $params;
        }

        if (!empty($params['locale'])) {
            $isValidLocale = is_string($params['locale']) && $params['locale'] !== '*';
            if ($isValidLocale) {
                return [...$params, 'data' => [...(is_array($params['data'] ?? null) ? $params['data'] : []), 'locale' => $params['locale']]];
            }

            $printed = is_array($params['locale']) ? implode(',', array_map('strval', $params['locale'])) : (string) $params['locale'];

            throw new ValidationError("Invalid locale param {$printed} provided. Document locales must be strings.");
        }

        return $params;
    }

    /**
     * Replace populated media values by their upload file ids (mutates and returns $data).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeMediaIds(Strapi $strapi, ?Schema $schema, array $data): array
    {
        if ($schema === null) {
            return $data;
        }

        foreach ($schema->attributes as $attributeName => $attribute) {
            $value = $data[$attributeName] ?? null;
            if ($value === null) {
                continue;
            }

            $type = $attribute['type'] ?? null;
            if ($type === 'media') {
                if ($attribute['multiple'] ?? false) {
                    $data[$attributeName] = is_array($value) && array_is_list($value)
                        ? array_map(static fn (mixed $file): mixed => is_array($file) && array_key_exists('id', $file) ? $file['id'] : $file, $value)
                        : $value;
                } else {
                    $data[$attributeName] = is_array($value) && array_key_exists('id', $value) ? $value['id'] : $value;
                }
                continue;
            }

            if ($type === 'component') {
                $componentSchema = $strapi->getModel((string) $attribute['component']);
                if (($attribute['repeatable'] ?? false) && is_array($value) && array_is_list($value)) {
                    $data[$attributeName] = array_map(static fn (mixed $v): mixed => is_array($v) ? self::normalizeMediaIds($strapi, $componentSchema, $v) : $v, $value);
                } elseif (is_array($value)) {
                    $data[$attributeName] = self::normalizeMediaIds($strapi, $componentSchema, $value);
                }
                continue;
            }

            if ($type === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                $data[$attributeName] = array_map(static function (mixed $componentValue) use ($strapi): mixed {
                    if (is_array($componentValue) && !empty($componentValue['__component'])) {
                        return self::normalizeMediaIds($strapi, $strapi->getModel((string) $componentValue['__component']), $componentValue);
                    }

                    return $componentValue;
                }, $value);
            }
        }

        return $data;
    }

    /**
     * Copy non-localized fields from an existing entry to a new entry being created for a different
     * locale of the same document.
     *
     * @param array<string, mixed> $dataToCreate
     * @return array<string, mixed>
     */
    public static function copyNonLocalizedFields(Strapi $strapi, Schema $contentType, string $documentId, array $dataToCreate): array
    {
        if (!$strapi->localization()->isLocalizedContentType($contentType)) {
            return $dataToCreate;
        }

        // Find an existing entry for the same document to copy unlocalized fields from
        $attributesToPopulate = $strapi->localization()->getNestedPopulateOfNonLocalizedAttributes($contentType->uid);
        $existingEntry = $strapi->db()->query($contentType->uid)->findOne([
            'where' => ['documentId' => $documentId],
            // Prefer published entry, but fall back to any entry
            'orderBy' => ['publishedAt' => 'desc'],
            'populate' => $attributesToPopulate === [] ? null : $attributesToPopulate,
        ]);

        // If an entry exists in another locale, copy its non-localized fields
        if ($existingEntry !== null) {
            $mergedData = $dataToCreate;
            $strapi->localization()->fillNonLocalizedAttributes($mergedData, $existingEntry, ['model' => $contentType->uid]);

            return self::normalizeMediaIds($strapi, $contentType, $mergedData);
        }

        return $dataToCreate;
    }
}
