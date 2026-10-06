<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Services\DocumentService\Transform\IdTransform;
use Strapi\Core\Services\DocumentService\Transform\Query;
use Strapi\Core\Services\DocumentService\Utils\BidirectionalRelations;
use Strapi\Core\Services\DocumentService\Utils\CloneRelations;
use Strapi\Core\Services\DocumentService\Utils\OrderedParallel;
use Strapi\Core\Services\DocumentService\Utils\Populate;
use Strapi\Core\Services\DocumentService\Utils\SelfReferentialRelations;
use Strapi\Core\Services\DocumentService\Utils\UnidirectionalRelations;
use Strapi\Core\Services\EntityValidator\EntityValidator;
use Strapi\Core\Strapi;
use Strapi\Core\Utils\TransformContentTypesToModels;
use Strapi\Types\Modules\Documents\Repository as RepositoryContract;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\ModelCache;
use Strapi\Utils\Validate\Validators;

/**
 * Port of services/document-service/repository.ts (`createContentTypeRepository`): the Document
 * Service of one content type. Every public method runs in a database transaction.
 *
 * Documents are rows sharing `document_id`; `status: 'draft'|'published'` selects rows by
 * `published_at IS NULL / NOT NULL` (for types without draft & publish everything is published).
 */
final class Repository implements RepositoryContract
{
    private readonly Schema $contentType;

    private readonly bool $hasDraftAndPublish;

    private readonly Entries $entries;

    private readonly Events $eventManager;

    private readonly Components $components;

    // Define the validations that should be performed
    private const SORT_VALIDATIONS = ['nonAttributesOperators', 'dynamicZones', 'morphRelations'];
    private const FIELD_VALIDATIONS = ['scalarAttributes'];
    private const FILTERS_VALIDATIONS = ['nonAttributesOperators', 'dynamicZones', 'morphRelations'];
    private const POPULATE_VALIDATIONS = [
        'sort' => self::SORT_VALIDATIONS,
        'field' => self::FIELD_VALIDATIONS,
        'filters' => self::FILTERS_VALIDATIONS,
        'populate' => ['nonAttributesOperators'],
    ];

    // BCP 47–style locale format
    private const LOCALE_FORMAT = '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/';
    private const MAX_LOCALE_LENGTH = 35;
    private const PAGINATION_KEYS = ['page', 'pageSize', 'start', 'limit', 'withCount'];

    public function __construct(private readonly Strapi $strapi, private readonly string $uid, EntityValidator $validator)
    {
        $this->contentType = $strapi->contentType($uid);
        $this->hasDraftAndPublish = ContentTypes::hasDraftAndPublish($this->contentType);
        $this->entries = Entries::createEntriesService($strapi, $uid, $validator);
        $this->eventManager = Events::createEventManager($strapi, $uid);
        $this->components = new Components($strapi);
    }

    public static function createContentTypeRepository(Strapi $strapi, string $uid, EntityValidator $validator): self
    {
        return new self($strapi, $uid, $validator);
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function hasDraftAndPublish(): bool
    {
        return $this->hasDraftAndPublish;
    }

    // --- param checks (strict mode) -----------------------------------------------------------

    /**
     * Publication actions look up every row matching `documentId`. An empty value would match all rows
     * whose document_id is NULL, so reject it outright.
     */
    private static function assertDocumentIdProvided(mixed $documentId, string $action): void
    {
        if (Params::isParamEmpty($documentId)) {
            throw new ValidationError("Cannot {$action} a document without a documentId");
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private static function checkStatus(array $params, bool $strict): array
    {
        if (!$strict) {
            return $params;
        }

        if (Params::isParamEmpty($params['status'] ?? null)) {
            unset($params['status']);

            return $params;
        }

        if ($params['status'] !== 'published' && $params['status'] !== 'draft') {
            $printed = is_scalar($params['status']) ? (string) $params['status'] : json_encode($params['status']);

            throw new ValidationError("Invalid parameter at 'status'. Expected 'published' or 'draft', received: {$printed}");
        }

        return $params;
    }

    private static function validateAndNormalizeLocale(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new ValidationError("Invalid parameter at '{$path}'. Expected a string, received: " . get_debug_type($value), ['received' => $value]);
        }
        if ($value === '*') {
            return $value;
        }
        $isEmpty = $value === '';
        $tooLong = strlen($value) > self::MAX_LOCALE_LENGTH;
        $invalidFormat = preg_match(self::LOCALE_FORMAT, $value) !== 1;
        if ($isEmpty || $tooLong || $invalidFormat) {
            $reason = $isEmpty ? 'Locale cannot be empty' : ($tooLong ? 'Locale exceeds maximum length of ' . self::MAX_LOCALE_LENGTH . ' characters' : 'Locale must be a valid BCP 47 format (e.g. en, en-US, zh-Hans)');

            throw new ValidationError("Invalid parameter at '{$path}'. {$reason}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private static function checkLocale(array $params, bool $strict): array
    {
        if (!$strict) {
            return $params;
        }

        if (Params::isParamEmpty($params['locale'] ?? null)) {
            unset($params['locale']);

            return $params;
        }

        $locale = $params['locale'];

        // Reject objects (we only accept string, array of strings, empty string, null, undefined)
        if (is_array($locale) && !array_is_list($locale)) {
            throw new ValidationError("Invalid parameter at 'locale'. Expected a string, array of strings, empty string, null, or undefined; received: object", ['received' => $locale]);
        }

        if (is_array($locale)) {
            $filtered = array_values(array_filter($locale, static fn (mixed $item): bool => is_string($item) && !Params::isParamEmpty($item)));
            if ($filtered === []) {
                unset($params['locale']);

                return $params;
            }
            $params['locale'] = array_map(static fn (string $item, int $i): string => self::validateAndNormalizeLocale($item, "locale[{$i}]"), $filtered, array_keys($filtered));

            return $params;
        }

        $params['locale'] = self::validateAndNormalizeLocale($locale, 'locale');

        return $params;
    }

    /** @param array{min: int, allowMinusOne?: bool} $spec */
    private static function parsePaginationInt(string $name, mixed $value, bool $strict, array $spec): ?int
    {
        if (Params::isParamEmpty($value)) {
            return null;
        }
        $num = is_numeric($value) ? $value + 0 : NAN;
        $valid = is_int($num) || (is_float($num) && floor($num) === $num && is_finite($num));
        $valid = $valid && ((int) $num >= $spec['min'] || (($spec['allowMinusOne'] ?? false) && (int) $num === -1));
        if (!$valid && $strict) {
            $expected = ($spec['allowMinusOne'] ?? false) ? "integer >= {$spec['min']} or -1" : "integer >= {$spec['min']}";
            $printed = is_scalar($value) ? (string) $value : json_encode($value);

            throw new ValidationError("Invalid parameter at '{$name}'. Expected {$expected}, received: {$printed}");
        }

        return $valid ? (int) $num : null;
    }

    private static function parseWithCount(mixed $value): ?bool
    {
        if (Params::isParamEmpty($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        throw new ValidationError("Invalid parameter at 'withCount'. Expected a boolean, received: " . get_debug_type($value));
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private static function checkPagination(array $params, bool $strict): array
    {
        if (!$strict) {
            return $params;
        }

        $hasPage = !Params::isParamEmpty($params['page'] ?? null) || !Params::isParamEmpty($params['pageSize'] ?? null);
        $hasOffset = !Params::isParamEmpty($params['start'] ?? null) || !Params::isParamEmpty($params['limit'] ?? null);
        if ($hasPage && $hasOffset) {
            throw new ValidationError('Invalid pagination parameters. Cannot use both page-based (page, pageSize) and offset-based (start, limit) pagination in the same query.');
        }

        $page = self::parsePaginationInt('page', $params['page'] ?? null, $strict, ['min' => 1]);
        $pageSize = self::parsePaginationInt('pageSize', $params['pageSize'] ?? null, $strict, ['min' => 1]);
        $start = self::parsePaginationInt('start', $params['start'] ?? null, $strict, ['min' => 0]);
        $limit = self::parsePaginationInt('limit', $params['limit'] ?? null, $strict, ['min' => 1, 'allowMinusOne' => true]);
        $withCount = self::parseWithCount($params['withCount'] ?? null);

        $result = array_diff_key($params, array_flip(self::PAGINATION_KEYS));
        if ($page !== null) {
            $result['page'] = $page;
        }
        if ($pageSize !== null) {
            $result['pageSize'] = $pageSize;
        }
        if ($start !== null) {
            $result['start'] = $start;
        }
        if ($limit !== null) {
            $result['limit'] = $limit;
        }
        if ($withCount !== null) {
            $result['withCount'] = $withCount;
        }

        return $result;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private static function checkUnrecognizedRootParams(array $params, bool $strict): array
    {
        if (!$strict) {
            return $params;
        }

        return array_intersect_key($params, array_flip(Params::ALLOWED_DOCUMENT_ROOT_PARAM_KEYS));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function validateParams(array $params): array
    {
        // Cache model lookups for this request to avoid repeating the same work
        $modelCache = ModelCache::createModelCache(fn (string $uid) => $this->strapi->getModel($uid));
        $ctx = ['schema' => $this->contentType, 'getModel' => $modelCache->callable()];

        // Only validate what is actually provided
        $validations = [];
        if (!empty($params['filters'])) {
            $validations[] = static fn () => Validators::validateFilters($ctx, $params['filters'], self::FILTERS_VALIDATIONS);
        }
        if (!empty($params['sort'])) {
            $validations[] = static fn () => Validators::validateSort($ctx, $params['sort'], self::SORT_VALIDATIONS);
        }
        if (!empty($params['fields'])) {
            $validations[] = static fn () => Validators::validateFields($ctx, $params['fields'], self::FIELD_VALIDATIONS);
        }
        if (!empty($params['populate'])) {
            $validations[] = static fn () => Validators::validatePopulate($ctx, $params['populate'], self::POPULATE_VALIDATIONS);
        }

        OrderedParallel::runParallelWithOrderedErrors($validations);
        $modelCache->clear();

        // Strip lookup from params, it's only used internally
        if (!empty($params['lookup'])) {
            throw new ValidationError("Invalid params: 'lookup'");
        }

        // config.api.documents.strictParams: false/undefined (pass through), true (throw on invalid)
        $rawStrictParams = $this->strapi->config()->get('api.documents.strictParams');

        if ($rawStrictParams !== null && $rawStrictParams !== false && $rawStrictParams !== true) {
            $printed = is_scalar($rawStrictParams) ? (string) $rawStrictParams : json_encode($rawStrictParams);

            throw new ValidationError("Invalid config.api.documents.strictParams value: \"{$printed}\". Expected boolean (true or false).");
        }

        $strict = $rawStrictParams === true;

        if ($strict) {
            $processed = self::checkUnrecognizedRootParams($params, true);
            $processed = self::checkStatus($processed, true);
            $processed = self::checkLocale($processed, true);

            return self::checkPagination($processed, true);
        }

        return $params;
    }

    // --- pipeline helpers --------------------------------------------------------------------

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function defaultLocale(array $params): array
    {
        return Internationalization::defaultLocale($this->strapi, $this->contentType, $params);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function localeToLookup(array $params): array
    {
        return Internationalization::localeToLookup($this->strapi, $this->contentType, $params);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function multiLocaleToLookup(array $params): array
    {
        return Internationalization::multiLocaleToLookup($this->strapi, $this->contentType, $params);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function transformParamsDocumentId(array $params): array
    {
        return IdTransform::transformParamsDocumentId($this->strapi, $this->uid, $params);
    }

    /** @param array<string, mixed>|null $params @return array<string, mixed> */
    private function transformParamsToQuery(?array $params): array
    {
        return Query::transformParamsToQuery($this->strapi, $this->uid, $params);
    }

    /** @param array<string, mixed> $entry */
    private function emitEvent(string $event, array $entry): void
    {
        $this->eventManager->emitEvent($event, $entry);
    }

    private function wrap(callable $fn): mixed
    {
        return $this->strapi->db()->transaction(static fn (): mixed => $fn());
    }

    // --- API ----------------------------------------------------------------------------------

    public function findMany(array $params = []): array
    {
        return $this->wrap(function () use ($params): array {
            $query = $this->validateParams($params);
            $query = DraftAndPublish::defaultToDraft($query);
            $query = DraftAndPublish::statusToLookup($this->contentType, $query);
            $query = $this->defaultLocale($query);
            $query = $this->multiLocaleToLookup($query);
            $query = $this->transformParamsDocumentId($query);
            $query = $this->transformParamsToQuery($query);

            return $this->strapi->db()->query($this->uid)->findMany($query);
        });
    }

    public function findFirst(array $params = []): ?array
    {
        return $this->wrap(function () use ($params): ?array {
            $query = $this->validateParams($params);
            $query = DraftAndPublish::defaultToDraft($query);
            $query = DraftAndPublish::statusToLookup($this->contentType, $query);
            $query = $this->defaultLocale($query);
            $query = $this->localeToLookup($query);
            $query = $this->transformParamsDocumentId($query);
            $query = $this->transformParamsToQuery($query);

            return $this->strapi->db()->query($this->uid)->findOne($query);
        });
    }

    public function findOne(array $params): ?array
    {
        return $this->wrap(function () use ($params): ?array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);

            $query = $this->validateParams($params);
            $query = DraftAndPublish::defaultToDraft($query);
            $query = DraftAndPublish::statusToLookup($this->contentType, $query);
            $query = $this->defaultLocale($query);
            $query = $this->localeToLookup($query);
            $query = $this->transformParamsDocumentId($query);
            $query = $this->transformParamsToQuery($query);
            $query = [...$query, 'where' => [...($query['where'] ?? []), 'documentId' => $documentId]];

            return $this->strapi->db()->query($this->uid)->findOne($query);
        });
    }

    public function delete(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);

            $lookupQuery = $this->validateParams($params);
            unset($lookupQuery['status']);
            $lookupQuery = $this->defaultLocale($lookupQuery);
            $lookupQuery = $this->multiLocaleToLookup($lookupQuery);
            $lookupQuery = $this->transformParamsToQuery($lookupQuery);
            $lookupQuery = [...$lookupQuery, 'where' => [...($lookupQuery['where'] ?? []), 'documentId' => $documentId]];

            $selectionQuery = $this->validateParams($params);
            unset($selectionQuery['status']);
            $selectionQuery = $this->transformParamsToQuery(Params::pickSelectionParams($selectionQuery));

            if ($this->hasDraftAndPublish && ($params['status'] ?? null) === 'draft') {
                throw new \RuntimeException('Cannot delete a draft document');
            }

            $entriesToDelete = $this->strapi->db()->query($this->uid)->findMany($lookupQuery);

            $deletedEntries = [];
            foreach ($entriesToDelete as $entryToDelete) {
                $deleted = $this->entries->delete($entryToDelete['id'], $selectionQuery);
                if ($deleted !== null) {
                    $deletedEntries[] = $deleted;
                }
            }

            foreach ($entriesToDelete as $entry) {
                $this->emitEvent(Events::ENTRY_DELETE, $entry);
            }

            return ['documentId' => (string) $documentId, 'entries' => $deletedEntries];
        });
    }

    public function create(array $params): array
    {
        return $this->wrap(function () use ($params): array {
            unset($params['documentId']);

            $queryParams = $this->validateParams($params);
            $queryParams = DraftAndPublish::filterDataPublishedAt($queryParams);
            $queryParams = DraftAndPublish::setStatusToDraft($this->contentType, $queryParams);
            $queryParams = DraftAndPublish::statusToData($this->contentType, $queryParams);
            $queryParams = $this->defaultLocale($queryParams);
            $queryParams = Internationalization::localeToData($this->strapi, $this->contentType, $queryParams);

            $doc = $this->entries->create($queryParams);

            $this->emitEvent(Events::ENTRY_CREATE, $doc);

            if ($this->hasDraftAndPublish && ($params['status'] ?? null) === 'published') {
                $published = $this->publish([...$params, 'documentId' => $doc['documentId']]);

                return $published['entries'][0] ?? $doc;
            }

            return $doc;
        });
    }

    /** @param array<string, mixed> $params @return array{documentId: string|null, entries: list<array<string, mixed>>} */
    public function clone(array $params = []): array
    {
        return $this->wrap(function () use ($params): array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);

            $queryParams = $this->validateParams($params);
            $queryParams = DraftAndPublish::filterDataPublishedAt($queryParams);
            $queryParams = $this->defaultLocale($queryParams);
            $queryParams = $this->multiLocaleToLookup($queryParams);

            // Get deep populate
            $entriesToClone = $this->strapi->db()->query($this->uid)->findMany([
                'where' => [
                    ...($queryParams['lookup'] ?? []),
                    'documentId' => $documentId,
                    // DP Enabled: Clone drafts; DP Disabled: Clone only the existing version (published)
                    'publishedAt' => ['$null' => $this->hasDraftAndPublish],
                ],
                'populate' => Populate::getDeepPopulate($this->strapi, $this->uid, ['relationalFields' => ['id']]),
            ]);

            $newDocumentId = TransformContentTypesToModels::createDocumentId();

            $clonedEntries = [];
            foreach ($entriesToClone as $entryToClone) {
                $sourceEntryId = $entryToClone['id'];
                $originalData = $entryToClone;
                unset($originalData['id'], $originalData['createdAt'], $originalData['updatedAt']);

                ['data' => $data, 'relationsToCopy' => $relationsToCopy] = CloneRelations::prepareCloneData(
                    $this->strapi,
                    $originalData,
                    is_array($queryParams['data'] ?? null) ? $queryParams['data'] : null,
                    $this->contentType,
                    fn (string $modelUid) => $this->strapi->getModel($modelUid),
                );
                $dataWithDocumentId = [...$data, 'documentId' => $newDocumentId];
                $doc = $this->entries->create([...$queryParams, 'data' => $dataWithDocumentId, 'status' => 'draft']);

                CloneRelations::copyCloneRelationRows($this->strapi, $this->uid, $sourceEntryId, $doc['id'], $relationsToCopy);

                if ($relationsToCopy === []) {
                    $clonedEntries[] = $doc;
                    continue;
                }

                $selectionQuery = $this->transformParamsToQuery(Params::pickSelectionParams([...$queryParams, 'status' => 'draft']));
                $clonedEntries[] = $this->strapi->db()->query($this->uid)->findOne([...$selectionQuery, 'where' => ['id' => $doc['id']]]) ?? $doc;
            }

            foreach ($clonedEntries as $entry) {
                $this->emitEvent(Events::ENTRY_CREATE, $entry);
            }

            return ['documentId' => $clonedEntries[0]['documentId'] ?? null, 'entries' => $clonedEntries];
        });
    }

    public function update(array $params): ?array
    {
        return $this->wrap(function () use ($params): ?array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);

            $queryParams = $this->validateParams($params);
            $queryParams = DraftAndPublish::filterDataPublishedAt($queryParams);
            $queryParams = FirstPublishedAt::filterDataFirstPublishedAt($queryParams);
            $queryParams = DraftAndPublish::setStatusToDraft($this->contentType, $queryParams);
            $queryParams = DraftAndPublish::statusToLookup($this->contentType, $queryParams);
            $queryParams = DraftAndPublish::statusToData($this->contentType, $queryParams);
            // Default locale will be set if not provided
            $queryParams = $this->defaultLocale($queryParams);
            $queryParams = $this->localeToLookup($queryParams);
            $queryParams = Internationalization::localeToData($this->strapi, $this->contentType, $queryParams);

            $restParams = $this->transformParamsDocumentId($queryParams);
            unset($restParams['data']);
            $query = $this->transformParamsToQuery(Params::pickSelectionParams($restParams));

            // Validation: find if document exists
            $entryToUpdate = $this->strapi->db()->query($this->uid)->findOne([
                ...$query,
                'where' => [...($queryParams['lookup'] ?? []), ...($query['where'] ?? []), 'documentId' => $documentId],
            ]);

            $updatedDraft = null;
            if ($entryToUpdate !== null) {
                $updatedDraft = $this->entries->update($entryToUpdate, $queryParams);
                if ($updatedDraft !== null) {
                    $this->emitEvent(Events::ENTRY_UPDATE, $updatedDraft);
                }
            }

            if ($updatedDraft === null) {
                $documentExists = $this->strapi->db()->query($this->contentType->uid)->findOne(['where' => ['documentId' => $documentId]]);

                if ($documentExists !== null) {
                    $mergedData = Internationalization::copyNonLocalizedFields($this->strapi, $this->contentType, (string) $documentId, [
                        ...(is_array($queryParams['data'] ?? null) ? $queryParams['data'] : []),
                        'documentId' => $documentId,
                    ]);

                    $updatedDraft = $this->entries->create([...$queryParams, 'data' => $mergedData]);
                    $this->emitEvent(Events::ENTRY_CREATE, $updatedDraft);
                }
            }

            if ($this->hasDraftAndPublish && $updatedDraft !== null && ($params['status'] ?? null) === 'published') {
                $published = $this->publish([...$params, 'documentId' => $documentId]);

                return $published['entries'][0] ?? $updatedDraft;
            }

            return $updatedDraft;
        });
    }

    public function count(array $params = []): int
    {
        return $this->wrap(function () use ($params): int {
            $query = $this->validateParams($params);
            $query = DraftAndPublish::defaultStatus($this->contentType, $query);
            $query = DraftAndPublish::statusToLookup($this->contentType, $query);
            $query = $this->defaultLocale($query);
            $query = $this->multiLocaleToLookup($query);
            $query = $this->transformParamsToQuery($query);

            return $this->strapi->db()->query($this->uid)->count($query);
        });
    }

    public function publish(array $params): array
    {
        if (!$this->hasDraftAndPublish) {
            throw new \BadMethodCallException("publish() is not available for {$this->uid}: draft & publish is disabled");
        }

        return $this->wrap(function () use ($params): array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);
            self::assertDocumentIdProvided($documentId, 'publish');

            $queryParams = $this->validateParams($params);
            $queryParams = $this->defaultLocale($queryParams);
            $queryParams = $this->multiLocaleToLookup($queryParams);

            $draftsToPublish = $this->strapi->db()->query($this->uid)->findMany([
                'where' => [...($queryParams['lookup'] ?? []), 'documentId' => $documentId, 'publishedAt' => null], // Ignore lookup
                // Populate relations, media, compos and dz
                'populate' => Populate::getDeepPopulate($this->strapi, $this->uid, ['relationalFields' => ['documentId', 'locale']]),
            ]);
            $oldPublishedVersions = $this->strapi->db()->query($this->uid)->findMany([
                'where' => [...($queryParams['lookup'] ?? []), 'documentId' => $documentId, 'publishedAt' => ['$ne' => null]],
                'select' => ['id', 'locale'],
            ]);

            // Load any unidirectional relation targeting the old published entries
            $relationsToSync = UnidirectionalRelations::load($this->strapi, $this->uid, ['newVersions' => $draftsToPublish, 'oldVersions' => $oldPublishedVersions], [
                'shouldPropagateRelation' => $this->components->createComponentRelationFilter(),
            ]);

            $bidirectionalRelationsToSync = BidirectionalRelations::load($this->strapi, $this->uid, ['newVersions' => $draftsToPublish, 'oldVersions' => $oldPublishedVersions]);

            $selfRelationsToSync = SelfReferentialRelations::load($this->strapi, $this->uid, $draftsToPublish, 'published');

            // Delete old published versions
            foreach ($oldPublishedVersions as $entry) {
                $this->entries->delete($entry['id']);
            }

            // Add firstPublishedAt to draft if it doesn't exist
            $updatedDraft = array_map(
                fn (array $draft): array => FirstPublishedAt::addFirstPublishedAtToDraft($draft, fn (array $e, array $p): ?array => $this->entries->update($e, $p), $this->contentType),
                $draftsToPublish,
            );

            // Transform draft entry data and create published versions
            $publishedEntries = array_map(fn (array $draft): array => $this->entries->publish($draft, $queryParams), $updatedDraft);

            // Sync relations with the new published entries
            UnidirectionalRelations::sync($this->strapi, [...$oldPublishedVersions, ...$updatedDraft], $publishedEntries, $relationsToSync);
            BidirectionalRelations::sync($this->strapi, [...$oldPublishedVersions, ...$updatedDraft], $publishedEntries, $bidirectionalRelationsToSync);
            SelfReferentialRelations::sync($this->strapi, [...$oldPublishedVersions, ...$updatedDraft], $publishedEntries, $selfRelationsToSync);

            foreach ($publishedEntries as $entry) {
                $this->emitEvent(Events::ENTRY_PUBLISH, $entry);
            }

            return ['documentId' => (string) $documentId, 'entries' => $publishedEntries];
        });
    }

    public function unpublish(array $params): array
    {
        if (!$this->hasDraftAndPublish) {
            throw new \BadMethodCallException("unpublish() is not available for {$this->uid}: draft & publish is disabled");
        }

        return $this->wrap(function () use ($params): array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);
            self::assertDocumentIdProvided($documentId, 'unpublish');

            $query = $this->validateParams($params);
            $query = $this->defaultLocale($query);
            $query = $this->multiLocaleToLookup($query);
            $query = $this->transformParamsToQuery($query);
            $query = [...$query, 'where' => [...($query['where'] ?? []), 'documentId' => $documentId, 'publishedAt' => ['$ne' => null]]];

            // Delete all published versions
            $versionsToDelete = $this->strapi->db()->query($this->uid)->findMany($query);
            foreach ($versionsToDelete as $entry) {
                $this->entries->delete($entry['id']);
            }

            foreach ($versionsToDelete as $entry) {
                $this->emitEvent(Events::ENTRY_UNPUBLISH, $entry);
            }

            return ['documentId' => (string) $documentId, 'entries' => $versionsToDelete];
        });
    }

    public function discardDraft(array $params): array
    {
        if (!$this->hasDraftAndPublish) {
            throw new \BadMethodCallException("discardDraft() is not available for {$this->uid}: draft & publish is disabled");
        }

        return $this->wrap(function () use ($params): array {
            $documentId = $params['documentId'] ?? null;
            unset($params['documentId']);
            self::assertDocumentIdProvided($documentId, 'discard the draft of');

            $queryParams = $this->validateParams($params);
            $queryParams = $this->defaultLocale($queryParams);
            $queryParams = $this->multiLocaleToLookup($queryParams);

            $versionsToDraft = $this->strapi->db()->query($this->uid)->findMany([
                'where' => [...($queryParams['lookup'] ?? []), 'documentId' => $documentId, 'publishedAt' => ['$ne' => null]],
                'populate' => Populate::getDeepPopulate($this->strapi, $this->uid, ['relationalFields' => ['documentId', 'locale']]),
            ]);
            $oldDrafts = $this->strapi->db()->query($this->uid)->findMany([
                'where' => [...($queryParams['lookup'] ?? []), 'documentId' => $documentId, 'publishedAt' => null],
                'select' => ['id', 'locale'],
            ]);

            // Load any unidirectional relation targeting the old drafts
            $relationsToSync = UnidirectionalRelations::load($this->strapi, $this->uid, ['newVersions' => $versionsToDraft, 'oldVersions' => $oldDrafts], [
                'shouldPropagateRelation' => $this->components->createComponentRelationFilter(),
            ]);

            $bidirectionalRelationsToSync = BidirectionalRelations::load($this->strapi, $this->uid, ['newVersions' => $versionsToDraft, 'oldVersions' => $oldDrafts]);

            $selfRelationsToSync = SelfReferentialRelations::load($this->strapi, $this->uid, $versionsToDraft, 'draft');

            // Delete old drafts
            foreach ($oldDrafts as $entry) {
                $this->entries->delete($entry['id']);
            }

            // Transform published entry data and create draft versions
            $draftEntries = array_map(fn (array $entry): array => $this->entries->discardDraft($entry, $queryParams), $versionsToDraft);

            UnidirectionalRelations::sync($this->strapi, [...$oldDrafts, ...$versionsToDraft], $draftEntries, $relationsToSync);
            BidirectionalRelations::sync($this->strapi, [...$oldDrafts, ...$versionsToDraft], $draftEntries, $bidirectionalRelationsToSync);
            SelfReferentialRelations::sync($this->strapi, [...$oldDrafts, ...$versionsToDraft], $draftEntries, $selfRelationsToSync);

            foreach ($draftEntries as $entry) {
                $this->emitEvent(Events::ENTRY_DRAFT_DISCARD, $entry);
            }

            return ['documentId' => (string) $documentId, 'entries' => $draftEntries];
        });
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateComponents(array $entry, array $data): array
    {
        return $this->components->updateComponents($this->uid, ['id' => $entry['id']], $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function omitComponentData(array $data): array
    {
        return Components::omitComponentData($this->contentType, $data);
    }
}
