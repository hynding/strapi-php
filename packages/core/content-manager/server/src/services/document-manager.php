<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\ContentManager\Services\Utils\Draft;
use Strapi\ContentManager\Services\Utils\DraftRelations;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Pagination;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/services/document-manager.ts.
 *
 * Options keep upstream's names. A `populate` option that is absent or `null` (upstream:
 * `undefined`) falls back to the deep populate.
 */
final class DocumentManager
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>|null
     */
    public function findOne(string $id, string $uid, array $opts = []): ?array
    {
        return $this->strapi->documents($uid)->findOne([...self::withoutNulls($opts), 'documentId' => $id]);
    }

    /**
     * Find multiple (or all) locales for a document
     *
     * @param string|list<string>|null $id
     * @param array{populate?: mixed, locale?: string|list<string>|null, isPublished?: bool} $opts
     * @return list<array<string, mixed>>
     */
    public function findLocales(string|array|null $id, string $uid, array $opts): array
    {
        // Will look for a specific locale by default
        $where = [];

        // Might not have an id if querying a single type
        if ($id !== null && $id !== '' && $id !== []) {
            $where['documentId'] = $id;
        }

        $locale = $opts['locale'] ?? null;
        // Search in array of locales
        if (is_array($locale)) {
            $where['locale'] = ['$in' => $locale];
        } elseif ($locale !== null && $locale !== '' && $locale !== '*') {
            // Look for a specific locale, ignore if looking for all locales
            $where['locale'] = $locale;
        }

        // Published is passed, so we filter on it, otherwise we don't filter
        if (is_bool($opts['isPublished'] ?? null)) {
            $where['publishedAt'] = ['$notNull' => $opts['isPublished']];
        }

        $params = ['where' => $where];
        if (isset($opts['populate'])) {
            $params['populate'] = $opts['populate'];
        }

        return array_values($this->strapi->db()->query($uid)->findMany($params));
    }

    /**
     * @param array<string, mixed> $opts
     * @return list<array<string, mixed>>
     */
    public function findMany(array $opts, string $uid): array
    {
        $params = [...self::withoutNulls($opts), 'populate' => Populate::getDeepPopulate($this->strapi, $uid)];

        return array_values($this->strapi->documents($uid)->findMany($params));
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{results: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function findPage(array $opts, string $uid): array
    {
        $params = Pagination::withDefaultPagination(self::withoutNulls($opts), [], 1000);

        $countParams = Objects::omit($params, ['populate', 'sort']);
        $documents = $this->strapi->documents($uid)->findMany($params);
        $total = $this->strapi->documents($uid)->count($countParams);

        return [
            'results' => array_values($documents),
            'pagination' => Pagination::transformPagedPaginationInfo($params, $total),
        ];
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public function create(string $uid, array $opts = []): array
    {
        $populate = $opts['populate'] ?? Populate::buildDeepPopulate($this->strapi, $uid);
        $params = [...self::withoutNulls($opts), 'status' => 'draft', 'populate' => $populate];

        return $this->strapi->documents($uid)->create(self::withoutNulls($params));
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>|null
     */
    public function update(string $id, string $uid, array $opts = []): ?array
    {
        $data = is_array($opts['data'] ?? null) ? $opts['data'] : [];
        $publishData = Objects::omit(Objects::omit($data, [ContentTypes::PUBLISHED_AT_ATTRIBUTE]), ['id']);
        $populate = $opts['populate'] ?? Populate::buildDeepPopulate($this->strapi, $uid);
        $params = [...self::withoutNulls($opts), 'data' => $publishData, 'populate' => $populate, 'status' => 'draft'];

        return $this->strapi->documents($uid)->update([...self::withoutNulls($params), 'documentId' => $id]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function clone(string $id, array $body, string $uid): ?array
    {
        $populate = Populate::buildDeepPopulate($this->strapi, $uid);

        // Extract the locale to pass it as a plain param
        $locale = $body['locale'] ?? null;
        $params = [
            // Ensure id and documentId are not copied to the clone
            'data' => Objects::omit($body, ['id', 'documentId']),
            'locale' => $locale,
            'populate' => $populate,
        ];

        $result = $this->strapi->documentService()($uid)->clone([...self::withoutNulls($params), 'documentId' => $id]);

        return $result['entries'][0] ?? null;
    }

    /**
     * Check if a document exists
     */
    public function exists(string $uid, ?string $id = null): bool
    {
        // Collection type
        if ($id !== null && $id !== '') {
            $count = $this->strapi->db()->query($uid)->count(['where' => ['documentId' => $id]]);

            return $count > 0;
        }

        // Single type
        $count = $this->strapi->db()->query($uid)->count();

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public function delete(string $id, string $uid, array $opts = []): array
    {
        $this->strapi->documents($uid)->delete([
            ...self::withoutNulls($opts),
            'documentId' => $id,
        ]);

        return [];
    }

    /**
     * FIXME: handle relations
     *
     * @param list<string> $documentIds
     * @param array<string, mixed> $opts
     * @return array{count: int}
     */
    public function deleteMany(array $documentIds, string $uid, array $opts = []): array
    {
        $deletedEntries = $this->strapi->db()->transaction(function () use ($documentIds, $uid, $opts): array {
            return array_map(fn (string $id): array => $this->delete($id, $uid, $opts), $documentIds);
        });

        return ['count' => count($deletedEntries)];
    }

    /**
     * @param array<string, mixed> $opts
     * @return list<array<string, mixed>>
     */
    public function publish(string $id, string $uid, array $opts = []): array
    {
        $populate = $opts['populate'] ?? Populate::buildDeepPopulate($this->strapi, $uid);
        $params = [...self::withoutNulls($opts), 'populate' => $populate];

        $result = $this->strapi->documents($uid)->publish([...self::withoutNulls($params), 'documentId' => $id]);

        return $result['entries'] ?? [];
    }

    /**
     * @param list<string> $documentIds
     * @param string|list<string>|null $locale
     */
    public function publishMany(string $uid, array $documentIds, string|array|null $locale = null): int
    {
        return $this->strapi->db()->transaction(function () use ($uid, $documentIds, $locale): int {
            $count = 0;
            foreach ($documentIds as $documentId) {
                $entries = $this->publish((string) $documentId, $uid, ['locale' => $locale, 'populate' => []]);
                $count += count(array_filter($entries));
            }

            return $count;
        });
    }

    /**
     * @param list<string> $documentIds
     * @param array<string, mixed> $opts
     * @return array{count: int}
     */
    public function unpublishMany(array $documentIds, string $uid, array $opts = []): array
    {
        $unpublishedEntitiesCount = $this->strapi->db()->transaction(function () use ($documentIds, $uid, $opts): int {
            $count = 0;
            foreach ($documentIds as $id) {
                $result = $this->strapi->documents($uid)->unpublish([...self::withoutNulls($opts), 'documentId' => $id, 'populate' => []]);
                $count += count(array_filter($result['entries'] ?? []));
            }

            return $count;
        });

        // Return the number of unpublished entities
        return ['count' => $unpublishedEntitiesCount];
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>|null
     */
    public function unpublish(string $id, string $uid, array $opts = []): ?array
    {
        $populate = $opts['populate'] ?? Populate::buildDeepPopulate($this->strapi, $uid);
        $params = [...self::withoutNulls($opts), 'populate' => $populate];

        $result = $this->strapi->documents($uid)->unpublish([...self::withoutNulls($params), 'documentId' => $id]);

        return $result['entries'][0] ?? null;
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>|null
     */
    public function discardDraft(string $id, string $uid, array $opts = []): ?array
    {
        $populate = $opts['populate'] ?? Populate::buildDeepPopulate($this->strapi, $uid);
        $params = [...self::withoutNulls($opts), 'populate' => $populate];

        $result = $this->strapi->documents($uid)->discardDraft([...self::withoutNulls($params), 'documentId' => $id]);

        return $result['entries'][0] ?? null;
    }

    /** @return array{unpublishedRelations: int, draftM2mLinks: int} */
    public function countDraftRelations(string $id, string $uid, ?string $locale): array
    {
        ['populate' => $populate, 'hasRelations' => $hasRelations] = Populate::getDeepPopulateDraftCount($this->strapi, $uid);

        if (!$hasRelations) {
            return DraftRelations::EMPTY_DRAFT_RELATION_COUNTS;
        }

        $document = $this->strapi->documents($uid)->findOne(self::withoutNulls(['documentId' => $id, 'populate' => $populate, 'locale' => $locale]));
        if ($document === null) {
            return DraftRelations::EMPTY_DRAFT_RELATION_COUNTS;
        }

        return Draft::sumDraftCounts($this->strapi, $document, $uid);
    }

    /**
     * @param list<string> $documentIds
     * @param string|list<string>|null $locale
     */
    public function countManyEntriesDraftRelations(array $documentIds, string $uid, string|array|null $locale): int
    {
        ['populate' => $populate, 'hasRelations' => $hasRelations] = Populate::getDeepPopulateDraftCount($this->strapi, $uid);

        if (!$hasRelations) {
            return 0;
        }

        $entities = $this->strapi->documents($uid)->findMany(self::withoutNulls([
            'populate' => $populate,
            'filters' => ['documentId' => ['$in' => $documentIds]],
            'locale' => $locale,
            'status' => 'draft',
        ]));

        $total = 0;
        foreach ($entities as $entity) {
            $entityCounts = Draft::sumDraftCounts($this->strapi, $entity, $uid);
            $total += $entityCounts['unpublishedRelations'] + $entityCounts['draftM2mLinks'];
        }

        return $total;
    }

    /**
     * JS `undefined` options are absent keys.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $params): array
    {
        return array_filter($params, static fn (mixed $value): bool => $value !== null);
    }
}
