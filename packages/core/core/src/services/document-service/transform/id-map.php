<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform;

use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\I18n;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;

/**
 * Port of transform/id-map.ts: holds a registry of document ids and their corresponding entity ids.
 *
 * @phpstan-type KeyFields array{uid: string, documentId: string|int, locale?: string|null, status?: 'draft'|'published'}
 */
final class IdMap
{
    /** @var array<string, string|int> */
    public array $loadedIds = [];

    /** @var array<string, KeyFields> */
    public array $toLoadIds = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createIdMap(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private function hasDraftAndPublish(string $uid): bool
    {
        return ContentTypes::hasDraftAndPublish($this->strapi->getModel($uid));
    }

    /**
     * Converts an object into a string by joining its keys and values, so it can be used as a map key:
     * `{ a: 1, b: 2 }` → `"a:::1&&b:::2"`.
     *
     * @param array<string, mixed> $obj
     */
    public function encodeKey(array $obj): string
    {
        // Ignore status field for models without draft and publish
        if (isset($obj['uid']) && !$this->hasDraftAndPublish((string) $obj['uid'])) {
            unset($obj['status']);
        }

        // Sort keys to always keep the same order when encoding
        ksort($obj, SORT_STRING);
        $parts = [];
        foreach ($obj as $key => $value) {
            $parts[] = "{$key}:::" . self::stringify($value);
        }

        return implode('&&', $parts);
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /** Register a new document id and its corresponding entity id. @param KeyFields $keyFields */
    public function add(array $keyFields): void
    {
        $key = $this->encodeKey(['status' => 'published', 'locale' => null, ...$keyFields]);

        // If the id is already loaded or already queued, do nothing
        if (array_key_exists($key, $this->loadedIds) || array_key_exists($key, $this->toLoadIds)) {
            return;
        }

        $this->toLoadIds[$key] = $keyFields;
    }

    /** Load all ids from the registry. */
    public function load(): void
    {
        // 1. Group ids to query together (by uid, locale and status)
        $idsByUidAndLocale = [];
        foreach ($this->toLoadIds as $fields) {
            $documentId = $fields['documentId'];
            $rest = $fields;
            unset($rest['documentId']);
            $key = $this->encodeKey($rest);
            $idsByUidAndLocale[$key] ??= [...$rest, 'documentIds' => []];
            $idsByUidAndLocale[$key]['documentIds'][] = $documentId;
        }

        // 2. Query ids
        foreach ($idsByUidAndLocale as $group) {
            $uid = (string) $group['uid'];
            $locale = $group['locale'] ?? null;
            $status = $group['status'] ?? null;

            $findParams = [
                'select' => ['id', 'documentId', 'publishedAt'],
                'where' => ['documentId' => ['$in' => $group['documentIds']]],
            ];

            // Without a localization provider, the model has no `locale` column
            $isLocalized = I18n::isLocalizedContentType($this->strapi, $uid);
            if ($isLocalized) {
                $findParams['select'][] = 'locale';
                $findParams['where']['locale'] = $locale ?: null;
            }

            if ($this->hasDraftAndPublish($uid)) {
                $findParams['where']['publishedAt'] = $status === 'draft' ? null : ['$ne' => null];
            }

            $result = $this->strapi->db()->query($uid)->findMany($findParams);

            // 3. Store result in loadedIds
            foreach ($result as $row) {
                $key = $this->encodeKey([
                    'documentId' => $row['documentId'],
                    'uid' => $uid,
                    'locale' => $isLocalized ? ($row['locale'] ?? null) : null,
                    'status' => ($row['publishedAt'] ?? null) !== null ? 'published' : 'draft',
                ]);
                $this->loadedIds[$key] = $row['id'];
            }
        }

        // 4. Clear toLoadIds
        $this->toLoadIds = [];
    }

    /** Get the entity id for a given document id. @param KeyFields $keys */
    public function get(array $keys): string|int|null
    {
        $key = $this->encodeKey(['status' => 'published', 'locale' => null, ...$keys]);

        return $this->loadedIds[$key] ?? null;
    }

    public function clear(): void
    {
        $this->loadedIds = [];
        $this->toLoadIds = [];
    }
}
