<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Services\DocumentService\DocumentServiceInstance;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/localizations.ts. */
final class Localizations
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Returns the provided data with populated media values replaced by their upload file IDs
     * (upstream mutates the object in place and returns the same reference).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeMediaIds(?Schema $schema, array $data): array
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
                $toId = static fn (mixed $file): mixed => is_array($file) && array_key_exists('id', $file) ? $file['id'] : $file;
                if (!empty($attribute['multiple'])) {
                    $data[$attributeName] = is_array($value) && array_is_list($value) ? array_map($toId, $value) : $value;
                } else {
                    $data[$attributeName] = $toId($value);
                }

                continue;
            }

            if ($type === 'component') {
                $componentSchema = $this->strapi->getModel((string) ($attribute['component'] ?? ''));

                if (!empty($attribute['repeatable']) && is_array($value) && array_is_list($value)) {
                    $data[$attributeName] = array_map(
                        fn (mixed $componentValue): mixed => is_array($componentValue) ? $this->normalizeMediaIds($componentSchema, $componentValue) : $componentValue,
                        $value,
                    );
                } elseif (is_array($value)) {
                    $data[$attributeName] = $this->normalizeMediaIds($componentSchema, $value);
                }

                continue;
            }

            if ($type === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                $data[$attributeName] = array_map(function (mixed $componentValue): mixed {
                    if (is_array($componentValue) && !empty($componentValue['__component'])) {
                        return $this->normalizeMediaIds($this->strapi->getModel((string) $componentValue['__component']), $componentValue);
                    }

                    return $componentValue;
                }, $value);
            }
        }

        return $data;
    }

    /**
     * Update non localized fields of all the related localizations of an entry with the entry values
     *
     * @param array<string, mixed> $sourceEntry
     */
    public function syncNonLocalizedAttributes(array $sourceEntry, Schema $model): void
    {
        $nonLocalizedAttributes = Utils::contentTypes($this->strapi)->copyNonLocalizedAttributes($model, $sourceEntry);
        if ($nonLocalizedAttributes === []) {
            return;
        }

        $normalizedNonLocalizedAttributes = $this->normalizeMediaIds($model, $nonLocalizedAttributes);

        $uid = $model->uid;
        $documentId = $sourceEntry['documentId'] ?? null;
        $locale = $sourceEntry['locale'] ?? null;
        $status = !empty($sourceEntry['publishedAt']) ? 'published' : 'draft';

        // Find all the entries that need to be updated
        // this is every other entry of the document in the same status but a different locale
        $localeEntriesToUpdate = $this->strapi->db()->query($uid)->findMany([
            'where' => [
                'documentId' => $documentId,
                'publishedAt' => $status === 'published' ? ['$ne' => null] : null,
                'locale' => ['$ne' => $locale],
            ],
            'select' => ['locale', 'id'],
        ]);

        $documents = $this->strapi->documents($uid);
        if (!$documents instanceof DocumentServiceInstance) {
            throw new \LogicException("The document service of {$uid} does not expose updateComponents / omitComponentData");
        }

        $entryData = $documents->omitComponentData($normalizedNonLocalizedAttributes);

        foreach ($localeEntriesToUpdate as $entry) {
            $transformedData = $this->strapi->documentService()->transformData($normalizedNonLocalizedAttributes, [
                'uid' => $uid,
                'status' => $status,
                'locale' => $entry['locale'] ?? null,
                'allowMissingId' => true,
            ]);

            // Update or create non localized components for the entry
            $componentData = $documents->updateComponents($entry, $transformedData);

            // Update every other locale entry of this documentId in the same status
            $this->strapi->db()->query($uid)->update([
                'where' => [
                    'documentId' => $documentId,
                    'publishedAt' => $status === 'published' ? ['$ne' => null] : null,
                    'locale' => ['$eq' => $entry['locale'] ?? null],
                ],
                // The data we send to the update function is the entry data merged with
                // the updated component data
                'data' => [...$entryData, ...$componentData],
            ]);
        }
    }
}
