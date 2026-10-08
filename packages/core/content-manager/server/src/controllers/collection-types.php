<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Controllers\Utils\CloneUtils;
use Strapi\ContentManager\Controllers\Utils\DocumentStatus;
use Strapi\ContentManager\Controllers\Utils\Metadata;
use Strapi\ContentManager\Controllers\Validation\Dimensions;
use Strapi\ContentManager\Controllers\Validation\Validation;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Services\Utils\DraftRelations;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\PublicationFilter;
use Strapi\Utils\SetCreatorFields;

/** Port of server/src/controllers/collection-types.ts. */
final class CollectionTypes
{
    /** Map from __status filter value to top-level query fields (mirrors client STATUS_PARAMS). */
    private const array STATUS_QUERY_FROM_FILTER = [
        'draft' => ['status' => 'draft', 'publicationFilter' => 'never-published'],
        'published' => ['status' => 'published'],
        'published-modified' => ['publicationStatusFilter' => 'published-modified'],
        'published-unmodified' => ['publicationStatusFilter' => 'published-unmodified'],
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Typed as `object` natively so that unit tests can register stubs (upstream tests mock services).
     *
     * @return PermissionChecker
     */
    private function permissionChecker(Context $ctx, string $model): object
    {
        return Utils::getService($this->strapi, 'permission-checker')->create([
            'userAbility' => $ctx->state()->get('userAbility'),
            'model' => $model,
        ]);
    }

    private static function model(Context $ctx): string
    {
        return (string) $ctx->param('model');
    }

    /** @return array<string, mixed> */
    private static function body(Context $ctx): array
    {
        $body = $ctx->requestBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed>|null $document
     * @param array{availableLocales?: bool, availableStatus?: bool} $opts
     * @return array<string, mixed>
     * @param PermissionChecker $permissionChecker
     */
    private function format(object $permissionChecker, string $model, mixed $document, array $opts = []): array
    {
        return Metadata::formatDocumentWithMetadata($this->strapi, $permissionChecker, $model, is_array($document) ? $document : null, $opts);
    }

    /**
     * Returns documentIds for (documentId, locale) that have both draft and published,
     * optionally filtered by whether the draft is newer than the published row.
     * Uses strapi.documents only.
     *
     * @param array{locale?: mixed, type: 'modified'|'unmodified'} $opts
     * @return list<string>
     */
    private function getDocumentIdsByDraftPublishRelation(string $uid, array $opts): array
    {
        $schema = $this->strapi->getModel($uid);
        if (!ContentTypes::hasDraftAndPublish($schema)) {
            return [];
        }

        $locale = $opts['locale'] ?? null;
        $baseParams = [
            'fields' => ['documentId', 'locale', 'updatedAt'],
            'page' => 1,
            'pageSize' => 10000,
            ...($locale !== null && $locale !== '*' ? ['locale' => $locale] : []),
        ];

        $drafts = $this->strapi->documents($uid)->findMany([...$baseParams, 'status' => 'draft']);
        $published = $this->strapi->documents($uid)->findMany([...$baseParams, 'status' => 'published']);

        $publishedByKey = [];
        foreach ($published as $p) {
            $key = ($p['documentId'] ?? '') . "\t" . (string) ($p['locale'] ?? '');
            $publishedByKey[$key] = ['updatedAt' => $p['updatedAt'] ?? null];
        }

        $ids = [];
        $wantModified = $opts['type'] === 'modified';
        foreach ($drafts as $d) {
            $key = ($d['documentId'] ?? '') . "\t" . (string) ($d['locale'] ?? '');
            $pub = $publishedByKey[$key] ?? null;
            if ($pub !== null) {
                $dUpdated = \Strapi\ContentManager\Services\DocumentMetadata::toTime($d['updatedAt'] ?? null);
                $pUpdated = \Strapi\ContentManager\Services\DocumentMetadata::toTime($pub['updatedAt']);
                $isModified = $dUpdated > $pUpdated;
                if ($isModified === $wantModified) {
                    $ids[] = (string) $d['documentId'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Extracts __status from query.filters.$and into top-level status, publicationFilter,
     * and publicationStatusFilter so list works with either transformed params or raw filter params.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private static function normalizeStatusFromFilters(array $query): array
    {
        $filters = $query['filters'] ?? null;
        if (!is_array($filters) || !is_array($filters['$and'] ?? null)) {
            return $query;
        }

        $remainingFilters = [];
        $statusValues = [];

        foreach ($filters['$and'] as $filter) {
            $eq = is_array($filter) && is_array($filter['__status'] ?? null) ? ($filter['__status']['$eq'] ?? null) : null;
            if ($eq !== null) {
                $statusValues[] = \Strapi\Utils\Primitives\Strings::stringify($eq);
            } else {
                $remainingFilters[] = $filter;
            }
        }

        if ($statusValues === []) {
            return $query;
        }

        foreach ($statusValues as $value) {
            $toApply = self::STATUS_QUERY_FROM_FILTER[$value] ?? null;
            if ($toApply !== null) {
                $query = [...$query, ...$toApply];
            }
        }

        if ($remainingFilters !== []) {
            $query['filters']['$and'] = $remainingFilters;
        } else {
            unset($query['filters']);
        }

        return $query;
    }

    /**
     * Returns filters object that merges existing $and with a documentId $in filter.
     *
     * @param list<string> $documentIds
     * @return array<string, mixed>
     */
    private static function mergeDocumentIdFilter(mixed $existingFilters, array $documentIds): array
    {
        $documentIdFilter = [
            'documentId' => ['$in' => $documentIds],
        ];
        if (is_array($existingFilters) && is_array($existingFilters['$and'] ?? null)) {
            $existingAnd = $existingFilters['$and'];
        } elseif (is_array($existingFilters) && $existingFilters !== []) {
            $existingAnd = [$existingFilters];
        } else {
            $existingAnd = [];
        }

        return ['$and' => [...array_values($existingAnd), $documentIdFilter]];
    }

    /**
     * Extracts the sort direction for the 'status' field from a sort parameter.
     * Returns 'ASC', 'DESC', or null if status is not being sorted.
     *
     * The sort param can be a string ('status:ASC'), an array (['status:ASC']),
     * or an object ({ status: 'ASC' }).
     */
    private static function extractStatusSortOrder(mixed $sort): ?string
    {
        if ($sort === null || $sort === '' || $sort === false) {
            return null;
        }

        if (is_string($sort)) {
            return preg_match('/(?:^|,)\s*status:(ASC|DESC)\s*(?:,|$)/i', $sort, $match) === 1 ? strtoupper($match[1]) : null;
        }

        if (is_array($sort) && array_is_list($sort)) {
            foreach ($sort as $item) {
                $result = self::extractStatusSortOrder($item);
                if ($result !== null) {
                    return $result;
                }
            }

            return null;
        }

        if (is_array($sort) && array_key_exists('status', $sort)) {
            $dir = strtoupper(\Strapi\Utils\Primitives\Strings::stringify($sort['status']));

            return $dir === 'ASC' || $dir === 'DESC' ? $dir : null;
        }

        return null;
    }

    /**
     * Removes the 'status' field from a sort parameter, returning the remainder
     * (or null — upstream `undefined` — if status was the only sort field).
     */
    private static function removeStatusFromSort(mixed $sort): mixed
    {
        if ($sort === null || $sort === '' || $sort === false) {
            return $sort;
        }

        if (is_string($sort)) {
            $parts = array_filter(
                array_map('trim', explode(',', $sort)),
                static fn (string $s): bool => preg_match('/^status:(ASC|DESC)$/i', $s) !== 1,
            );
            $cleaned = implode(',', $parts);

            return $cleaned !== '' ? $cleaned : null;
        }

        if (is_array($sort) && array_is_list($sort)) {
            $cleaned = array_values(array_filter($sort, static fn (mixed $item): bool => self::extractStatusSortOrder($item) === null));

            return $cleaned !== [] ? $cleaned : null;
        }

        if (is_array($sort)) {
            $rest = $sort;
            unset($rest['status']);

            return $rest !== [] ? $rest : null;
        }

        return $sort;
    }

    /**
     * Create a new document.
     *
     * @param array{populate?: mixed} $opts populate: options of the returned document. By default documentManager will populate all relations.
     * @return array<string, mixed>
     */
    private function createDocument(Context $ctx, array $opts = []): array
    {
        $user = $ctx->state()->get('user');
        $model = self::model($ctx);
        $body = self::body($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('create')) {
            throw new ForbiddenError();
        }

        $sanitized = $permissionChecker->sanitizeCreateInput($body);
        $sanitizedBody = SetCreatorFields::create(['user' => $user])(is_array($sanitized) ? $sanitized : []);

        ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

        return $documentManager->create($model, [
            'data' => $sanitizedBody,
            'locale' => $locale,
            'status' => $status,
            'populate' => $opts['populate'] ?? null,
        ]);

        // TODO: Revert the creation if create permission conditions are not met
        // if (permissionChecker.cannot.create(document)) {
        //   throw new errors.ForbiddenError();
        // }
    }

    /**
     * Update a document version.
     * - If the document version exists, it will be updated.
     * - If the document version does not exist, a new document locale will be created.
     *   By default documentManager will populate all relations.
     *
     * @param array{populate?: mixed} $opts populate: options of the returned document
     * @return array<string, mixed>|null
     */
    private function updateDocument(Context $ctx, array $opts = []): ?array
    {
        $user = $ctx->state()->get('user');
        $id = (string) $ctx->param('id');
        $model = self::model($ctx);
        $body = self::body($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('update')) {
            throw new ForbiddenError();
        }

        // Populate necessary fields to check permissions
        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'update');
        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->build();

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

        // Load document version to update
        $documentVersion = $documentManager->findOne($id, $model, ['populate' => $populate, 'locale' => $locale, 'status' => 'draft']);
        $documentExists = $documentManager->exists($model, $id);

        if (!$documentExists) {
            throw new NotFoundError();
        }

        // If version is not found, but document exists,
        // the intent is to create a new document locale
        if ($documentVersion !== null) {
            if ($permissionChecker->cannot('update', $documentVersion)) {
                throw new ForbiddenError();
            }
        } elseif ($permissionChecker->cannot('create')) {
            throw new ForbiddenError();
        }

        $sanitized = $documentVersion !== null
            ? $permissionChecker->sanitizeUpdateInput($documentVersion)($body)
            : $permissionChecker->sanitizeCreateInput($body);
        $setCreator = $documentVersion !== null
            ? SetCreatorFields::create(['user' => $user, 'isEdition' => true])
            : SetCreatorFields::create(['user' => $user]);
        $sanitizedBody = $setCreator(is_array($sanitized) ? $sanitized : []);

        return $documentManager->update((string) ($documentVersion['documentId'] ?? $id), $model, [
            'data' => $sanitizedBody,
            'populate' => $opts['populate'] ?? null,
            'locale' => $locale,
        ]);
    }

    public function find(Context $ctx): mixed
    {
        $model = self::model($ctx);

        // Normalize so status/publicationStatusFilter are set from filters.$and.__status when present
        $query = self::normalizeStatusFromFilters($ctx->query());
        $ctx->setQuery($query);

        $documentMetadata = Utils::getService($this->strapi, 'document-metadata');
        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        // Extract and remove 'status' sort before sanitization/validation, since status
        // is not a real schema attribute and would be rejected by the permission checker.
        // It is re-added after sanitization so the DB layer can handle it via a CASE expression.
        $hasPublicationStatusFilter = array_key_exists('status', $query)
            || array_key_exists('hasPublishedVersion', $query)
            || array_key_exists('publicationStatusFilter', $query);
        $rawStatusSortOrder = self::extractStatusSortOrder($query['sort'] ?? null);
        // Disable status sort when a publication status filter is active — all results share the
        // same status, making the sort a no-op.
        $statusSortOrder = $hasPublicationStatusFilter ? null : $rawStatusSortOrder;
        // Always strip 'status' from the sort before passing to the document service / permission
        // checker — it is not a real schema attribute and would be rejected by validation.
        $queryWithoutStatusSort = $query;
        if ($rawStatusSortOrder !== null) {
            $sortWithoutStatus = self::removeStatusFromSort($query['sort'] ?? null);
            if ($sortWithoutStatus === null) {
                unset($queryWithoutStatusSort['sort']);
            } else {
                $queryWithoutStatusSort['sort'] = $sortWithoutStatus;
            }
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($queryWithoutStatusSort, 'read');

        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->populateDeep(1)
            ->countRelations(['toOne' => false, 'toMany' => true])
            ->withPopulateOverride(Populate::getPopulateForLocalizations($this->strapi, $model))
            ->build();

        // "Modified" is a UI-only filter; not a real document status. Read and strip it
        // so we never pass it to validation or the document service.
        $publicationStatusFilter = $query['publicationStatusFilter'] ?? null;
        $queryForValidation = $query;
        unset($queryForValidation['publicationStatusFilter']);

        ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $queryForValidation, $model);

        $paramsForDocumentService = Objects::omit($permissionQuery, ['publicationStatusFilter']);
        $findPageParams = [
            ...$paramsForDocumentService,
            'populate' => $populate,
            'locale' => $locale,
            'status' => $status,
        ];

        if (array_key_exists('publicationFilter', $query)) {
            $findPageParams['publicationFilter'] = $query['publicationFilter'];
        } else {
            $legacy = PublicationFilter::parseHasPublishedVersionQueryParam($query['hasPublishedVersion'] ?? null);
            if ($legacy !== null) {
                $findPageParams['publicationFilter'] = PublicationFilter::hasPublishedVersionBooleanToPublicationFilterMode($legacy);
            }
        }

        if ($publicationStatusFilter === 'published-modified' || $publicationStatusFilter === 'published-unmodified') {
            $type = $publicationStatusFilter === 'published-modified' ? 'modified' : 'unmodified';
            $documentIds = $this->getDocumentIdsByDraftPublishRelation($model, ['locale' => $locale, 'type' => $type]);
            $findPageParams = [
                ...$findPageParams,
                'status' => 'published',
                'filters' => self::mergeDocumentIdFilter($paramsForDocumentService['filters'] ?? null, $documentIds),
            ];
        }

        if ($statusSortOrder !== null) {
            $s = $findPageParams['sort'] ?? null;
            $statusSort = "status:{$statusSortOrder}";
            if ($s === null || $s === '' || $s === []) {
                $findPageParams['sort'] = $statusSort;
            } elseif (is_array($s) && array_is_list($s)) {
                $findPageParams['sort'] = [...$s, $statusSort];
            } elseif (is_string($s)) {
                $findPageParams['sort'] = "{$s},{$statusSort}";
            } elseif (is_array($s)) {
                $findPageParams['sort'] = [...$s, 'status' => $statusSortOrder];
            }
        }

        /** @var array<string, mixed> $findPageParams */
        ['results' => $documents, 'pagination' => $pagination] = $documentManager->findPage($findPageParams, $model);

        $hasDraftAndPublish = ContentTypes::hasDraftAndPublish($this->strapi->getModel($model));

        $statusByDocumentId = $hasDraftAndPublish
            ? DocumentStatus::indexByDocumentId($documentMetadata->getManyAvailableStatus($model, $documents))
            : [];

        $results = [];
        foreach ($documents as $document) {
            $document = $permissionChecker->sanitizeOutput($document);
            if (is_array($document)) {
                // Available status of document
                $availableStatuses = $statusByDocumentId[(string) ($document['documentId'] ?? '')] ?? [];
                // Compute document version status
                $document['status'] = $documentMetadata->getStatus($document, $availableStatuses);
            }
            $results[] = $document;
        }

        $ctx->setBody([
            'results' => $results,
            'pagination' => $pagination,
        ]);

        return null;
    }

    public function findOne(Context $ctx): mixed
    {
        $model = self::model($ctx);
        $id = (string) $ctx->param('id');

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'read');

        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->populateDeep(INF)
            ->countRelations()
            ->withPopulateOverride(Populate::getPopulateForLocalizations($this->strapi, $model))
            ->build();

        ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $ctx->query(), $model);

        $version = $documentManager->findOne($id, $model, [
            'populate' => $populate,
            'locale' => $locale,
            'status' => $status,
        ]);

        if ($version === null) {
            // Check if document exists
            $exists = $documentManager->exists($model, $id);
            if (!$exists) {
                $ctx->notFound();

                return null;
            }

            // If the requested locale doesn't exist, return an empty response
            ['meta' => $meta] = $this->format(
                $permissionChecker,
                $model,
                ['documentId' => $id, 'locale' => $locale, 'publishedAt' => null],
                ['availableLocales' => true, 'availableStatus' => false],
            );

            $ctx->setBody(['data' => new \stdClass(), 'meta' => $meta]);

            return null;
        }

        // if the user has condition that needs populated content, it's not applied because entity don't have relations populated
        if ($permissionChecker->cannot('read', $version)) {
            $ctx->forbidden();

            return null;
        }

        // TODO: Count populated relations by permissions
        $sanitizedDocument = $permissionChecker->sanitizeOutput($version);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument));

        return null;
    }

    public function create(Context $ctx): mixed
    {
        $model = self::model($ctx);

        $permissionChecker = $this->permissionChecker($ctx, $model);

        $totalEntries = $this->strapi->db()->query($model)->count();
        $document = $this->createDocument($ctx);

        $sanitizedDocument = $permissionChecker->sanitizeOutput($document);
        $ctx->setStatus(201);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument, [
            // Empty metadata as it's not relevant for a new document
            'availableLocales' => false,
            'availableStatus' => false,
        ]));

        if ($totalEntries === 0) {
            $this->strapi->telemetry()->send('didCreateFirstContentTypeEntry', [
                'eventProperties' => ['model' => $model],
            ]);
        }

        return null;
    }

    public function update(Context $ctx): mixed
    {
        $model = self::model($ctx);

        $permissionChecker = $this->permissionChecker($ctx, $model);

        $updatedVersion = $this->updateDocument($ctx);

        $sanitizedVersion = $permissionChecker->sanitizeOutput($updatedVersion);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedVersion));

        return null;
    }

    public function clone(Context $ctx): mixed
    {
        $user = $ctx->state()->get('user');
        $model = self::model($ctx);
        $id = (string) $ctx->param('sourceId');
        $body = self::body($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('create')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'create');
        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->build();

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);
        $document = $documentManager->findOne($id, $model, [
            'populate' => $populate,
            'locale' => $locale,
            'status' => 'draft',
        ]);

        if ($document === null) {
            $ctx->notFound();

            return null;
        }

        $sanitized = $permissionChecker->sanitizeCreateInput($body);
        $sanitized = SetCreatorFields::create(['user' => $user])(is_array($sanitized) ? $sanitized : []);
        $sanitizedBody = CloneUtils::excludeNotCreatableFields($this->strapi, $model, $permissionChecker)($sanitized);

        $clonedDocument = $documentManager->clone((string) $document['documentId'], $sanitizedBody, $model);

        $sanitizedDocument = $permissionChecker->sanitizeOutput($clonedDocument);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument, [
            // Empty metadata as it's not relevant for a new document
            'availableLocales' => false,
            'availableStatus' => false,
        ]));

        return null;
    }

    public function autoClone(Context $ctx): mixed
    {
        $model = self::model($ctx);

        // Check if the model has fields that prevent auto cloning
        $prohibitedFields = CloneUtils::getProhibitedCloningFields($this->strapi, $model);

        if ($prohibitedFields !== []) {
            $ctx->badRequest(
                'Entity could not be cloned as it has unique and/or relational fields. '
                . 'Please edit those fields manually and save to complete the cloning.',
                [
                    'prohibitedFields' => $prohibitedFields,
                ],
            );

            return null;
        }

        return $this->clone($ctx);
    }

    public function delete(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');
        $model = self::model($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('delete')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'delete');
        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->build();

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $ctx->query(), $model);

        // Find locales to delete
        $documentLocales = $documentManager->findLocales($id, $model, ['populate' => $populate, 'locale' => $locale]);

        if ($documentLocales === []) {
            $ctx->notFound();

            return null;
        }

        foreach ($documentLocales as $document) {
            if ($permissionChecker->cannot('delete', $document)) {
                $ctx->forbidden();

                return null;
            }
        }

        $result = $documentManager->delete($id, $model, ['locale' => $locale]);

        $sanitized = $permissionChecker->sanitizeOutput($result);
        $ctx->setBody($sanitized === [] ? new \stdClass() : $sanitized);

        return null;
    }

    /**
     * Publish a document version.
     * Supports creating/saving a document and publishing it in one request.
     */
    public function publish(Context $ctx): mixed
    {
        // If id does not exist, the document has to be created
        $id = $ctx->param('id');
        $model = self::model($ctx);
        $body = self::body($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('publish')) {
            $ctx->forbidden();

            return null;
        }

        $publishedDocument = $this->strapi->db()->transaction(function () use ($ctx, $id, $model, $body, $documentManager, $permissionChecker): mixed {
            // Create or update document
            $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'publish');

            $populate = Utils::getService($this->strapi, 'populate-builder')($model)
                ->populateFromQuery($permissionQuery)
                ->populateDeep(INF)
                ->countRelations()
                ->withPopulateOverride(Populate::getPopulateForLocalizations($this->strapi, $model))
                ->build();

            $document = null;

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

            /**
             * Publish can be called on two scenarios:
             * 1. Create a new document and publish it in one request
             * 2. Update an existing document and publish it in one request
             *
             * Based on user permissions:
             * 1. User cannot create a document, but can publish
             *    Action will be forbidden as user cannot create a document
             * 2. User can update and publish a document
             *    Action will be allowed, but document will not be updated, only published with the latest draft
             */
            $isCreate = $id === null;
            if ($isCreate) {
                if ($permissionChecker->cannot('create')) {
                    throw new ForbiddenError();
                }

                $document = $this->createDocument($ctx, ['populate' => $populate]);
            }

            $isUpdate = !$isCreate;
            if ($isUpdate) {
                // check if the document exists
                $documentExists = $documentManager->exists($model, $id);

                if (!$documentExists) {
                    throw new NotFoundError('Document not found');
                }

                // check the document version
                $document = $documentManager->findOne($id, $model, ['populate' => $populate, 'locale' => $locale]);

                if ($document === null) {
                    // update and publish the new version
                    if (
                        $permissionChecker->cannot('create', ['locale' => $locale])
                        || $permissionChecker->cannot('publish', ['locale' => $locale])
                    ) {
                        throw new ForbiddenError();
                    }
                    $document = $this->updateDocument($ctx);
                } elseif ($permissionChecker->can('update', $document)) {
                    $this->updateDocument($ctx);
                }
            }

            if ($permissionChecker->cannot('publish', $document)) {
                throw new ForbiddenError();
            }

            $publishResult = $documentManager->publish((string) ($document['documentId'] ?? ''), $model, [
                'locale' => $locale,
                // TODO: Allow setting creator fields on publish
                // data: setCreatorFields({ user, isEdition: true })({}),
            ]);

            if ($publishResult === []) {
                throw new NotFoundError('Document not found or already published.');
            }

            return $publishResult[0];
        });

        $sanitizedDocument = $permissionChecker->sanitizeOutput($publishedDocument);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument));

        return null;
    }

    public function bulkFindForValidation(Context $ctx): mixed
    {
        $model = self::model($ctx);
        $body = self::body($ctx);
        $documentIds = $body['documentIds'] ?? null;

        Validation::validateBulkActionInput($ctx->requestBody());

        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        $populate = Populate::buildDeepPopulate($this->strapi, $model);

        $documents = $this->strapi->documents($model)->findMany(array_filter([
            'populate' => $populate,
            'filters' => ['documentId' => ['$in' => $documentIds]],
            'locale' => $body['locale'] ?? null,
            'sort' => $body['sort'] ?? null,
            'status' => 'draft',
        ], static fn (mixed $value): bool => $value !== null));

        $results = array_map(static fn (array $document): mixed => $permissionChecker->sanitizeOutput($document), $documents);

        $ctx->setBody(['results' => array_values($results)]);

        return null;
    }

    public function bulkPublish(Context $ctx): mixed
    {
        $model = self::model($ctx);
        $body = self::body($ctx);

        Validation::validateBulkActionInput($ctx->requestBody());
        /** @var list<string> $documentIds */
        $documentIds = array_map('strval', $body['documentIds']);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('publish')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'publish');
        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->populateDeep(INF)
            ->countRelations()
            ->build();

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model, [
            'allowMultipleLocales' => true,
        ]);

        $entities = $documentManager->findLocales($documentIds, $model, [
            'populate' => $populate,
            'locale' => $locale,
            'isPublished' => false,
        ]);

        foreach ($entities as $entity) {
            if ($permissionChecker->cannot('publish', $entity)) {
                $ctx->forbidden();

                return null;
            }
        }

        $count = $documentManager->publishMany($model, $documentIds, $locale);
        $ctx->setBody(['count' => $count]);

        return null;
    }

    public function bulkUnpublish(Context $ctx): mixed
    {
        $model = self::model($ctx);
        $body = self::body($ctx);

        Validation::validateBulkActionInput($ctx->requestBody());
        /** @var list<string> $documentIds */
        $documentIds = array_map('strval', $body['documentIds']);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('unpublish')) {
            $ctx->forbidden();

            return null;
        }

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model, [
            'allowMultipleLocales' => true,
        ]);

        $entities = $documentManager->findLocales($documentIds, $model, [
            'locale' => $locale,
            'isPublished' => true,
        ]);

        foreach ($entities as $entity) {
            if ($permissionChecker->cannot('publish', $entity)) {
                $ctx->forbidden();

                return null;
            }
        }

        $entitiesIds = array_map(static fn (array $document): string => (string) $document['documentId'], $entities);

        ['count' => $count] = $documentManager->unpublishMany($entitiesIds, $model, ['locale' => $locale]);

        $ctx->setBody(['count' => $count]);

        return null;
    }

    public function unpublish(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');
        $model = self::model($ctx);
        $body = self::body($ctx);
        $discardDraft = $body['discardDraft'] ?? null;
        unset($body['discardDraft']);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('unpublish')) {
            $ctx->forbidden();

            return null;
        }

        if (!empty($discardDraft) && $permissionChecker->cannot('discard')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'unpublish');

        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->build();

        // TODO allow multiple locales for bulk locale unpublish
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);
        $document = $documentManager->findOne($id, $model, [
            'populate' => $populate,
            'locale' => $locale,
            'status' => 'published',
        ]);

        if ($document === null) {
            throw new NotFoundError();
        }

        if ($permissionChecker->cannot('unpublish', $document)) {
            throw new ForbiddenError();
        }

        if (!empty($discardDraft) && $permissionChecker->cannot('discard', $document)) {
            throw new ForbiddenError();
        }

        $this->strapi->db()->transaction(function () use ($ctx, $discardDraft, $documentManager, $document, $model, $locale, $permissionChecker): void {
            if (!empty($discardDraft)) {
                $documentManager->discardDraft((string) $document['documentId'], $model, ['locale' => $locale]);
            }

            $unpublished = $documentManager->unpublish((string) $document['documentId'], $model, ['locale' => $locale]);
            $sanitized = $permissionChecker->sanitizeOutput($unpublished);
            $ctx->setBody($this->format($permissionChecker, $model, $sanitized));
        });

        return null;
    }

    public function discard(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');
        $model = self::model($ctx);
        $body = self::body($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('discard')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'discard');
        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->build();

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);
        $document = $documentManager->findOne($id, $model, [
            'populate' => $populate,
            'locale' => $locale,
            'status' => 'published',
        ]);

        // Can not discard a document that is not published
        if ($document === null) {
            $ctx->notFound();

            return null;
        }

        if ($permissionChecker->cannot('discard', $document)) {
            $ctx->forbidden();

            return null;
        }

        $discarded = $documentManager->discardDraft((string) $document['documentId'], $model, ['locale' => $locale]);
        $sanitized = $permissionChecker->sanitizeOutput($discarded);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitized));

        return null;
    }

    public function bulkDelete(Context $ctx): mixed
    {
        $model = self::model($ctx);
        $query = $ctx->query();
        $body = self::body($ctx);

        Validation::validateBulkActionInput($ctx->requestBody());
        /** @var list<string> $documentIds */
        $documentIds = array_map('strval', $body['documentIds']);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('delete')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($query, 'delete');
        $populate = Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($permissionQuery)
            ->build();

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

        $documentLocales = $documentManager->findLocales($documentIds, $model, [
            'populate' => $populate,
            'locale' => $locale,
        ]);

        if ($documentLocales === []) {
            $ctx->notFound();

            return null;
        }

        foreach ($documentLocales as $document) {
            if ($permissionChecker->cannot('delete', $document)) {
                $ctx->forbidden();

                return null;
            }
        }

        // We filter out documentsIds that maybe doesn't exist in a specific locale.
        // With draft & publish, findLocales returns a row per publication state, so the
        // same documentId can appear twice (draft + published). Deduplicate to avoid
        // deleting (and running document service middleware for) the same document twice.
        $localeDocumentsIds = array_values(array_unique(array_map(static fn (array $document): string => (string) $document['documentId'], $documentLocales)));

        ['count' => $count] = $documentManager->deleteMany($localeDocumentsIds, $model, ['locale' => $locale]);

        $ctx->setBody(['count' => $count]);

        return null;
    }

    public function countDraftRelations(Context $ctx): mixed
    {
        $model = self::model($ctx);
        $id = (string) $ctx->param('id');

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $ctx->query(), $model);

        if ($permissionChecker->requiresEntity('read')) {
            // Only load what we need for access checks
            $permissionQuery = $permissionChecker->sanitizedQuery($ctx->query(), 'read');

            $populate = Utils::getService($this->strapi, 'populate-builder')($model)
                ->populateFromQuery($permissionQuery)
                ->build();

            $entity = $documentManager->findOne($id, $model, [
                'locale' => $locale,
                'status' => $status,
                'populate' => $populate,
            ]);

            if ($entity === null) {
                // The document may simply not have a version in the requested locale yet.
                // Check every existing locale/status version before deciding it truly doesn't exist —
                // findLocales returns one row per locale AND per publication state.
                $versions = $documentManager->findLocales($id, $model, ['populate' => $populate]);

                if ($versions === []) {
                    $ctx->notFound();

                    return null;
                }

                if ($permissionChecker->requiresEntity('read')) {
                    $allForbidden = true;
                    foreach ($versions as $version) {
                        if (!$permissionChecker->cannot('read', $version)) {
                            $allForbidden = false;
                            break;
                        }
                    }
                    if ($allForbidden) {
                        $ctx->forbidden();

                        return null;
                    }
                }

                return ['data' => DraftRelations::EMPTY_DRAFT_RELATION_COUNTS];
            }

            if ($permissionChecker->cannot('read', $entity)) {
                $ctx->forbidden();

                return null;
            }
        }

        $counts = $documentManager->countDraftRelations($id, $model, is_string($locale) ? $locale : null);

        return [
            'data' => $counts,
        ];
    }

    public function countManyEntriesDraftRelations(Context $ctx): mixed
    {
        $query = $ctx->query();
        $ids = $query['documentIds'] ?? null;
        $locale = $query['locale'] ?? null;
        $model = self::model($ctx);

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        $count = $this->strapi->db()->query($model)->count([
            'where' => ['documentId' => $ids],
        ]);

        if ($count === 0) {
            $ctx->notFound();

            return null;
        }

        $number = $documentManager->countManyEntriesDraftRelations(
            is_array($ids) ? array_values(array_map('strval', $ids)) : (is_string($ids) ? [$ids] : []),
            $model,
            is_string($locale) ? $locale : (is_array($locale) ? array_values(array_map('strval', $locale)) : null),
        );

        return [
            'data' => $number,
        ];
    }
}
