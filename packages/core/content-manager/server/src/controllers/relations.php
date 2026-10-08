<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Controllers\Utils\DocumentStatus;
use Strapi\ContentManager\Controllers\Validation\Relations as RelationsValidation;
use Strapi\ContentManager\Services\Utils\Configuration\Attributes;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Operators;
use Strapi\Utils\Relations as RelationsUtils;

/**
 * Port of server/src/controllers/relations.ts.
 *
 * `strapi.plugin('i18n').service('content-types').isLocalizedContentType` falls back to core's
 * localization service while the i18n plugin is not ported.
 */
final class Relations
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $filtersClause
     */
    private static function addFiltersClause(array &$params, array $filtersClause): void
    {
        $params['filters'] = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $params['filters']['$and'] = is_array($params['filters']['$and'] ?? null) ? $params['filters']['$and'] : [];
        $params['filters']['$and'][] = $filtersClause;
    }

    private function sanitizeMainField(Schema $model, mixed $mainField, mixed $userAbility): string
    {
        $permissionChecker = Utils::getService($this->strapi, 'permission-checker')->create([
            'userAbility' => $userAbility,
            'model' => $model->uid,
        ]);

        // Whether the main field can be displayed or not, regardless of permissions.
        $isMainFieldListable = Attributes::isListable(ContentTypes::toArray($model), $mainField);
        // Whether the user has the permission to access the model's main field (using RBAC abilities)
        $canReadMainField = $permissionChecker->can('read', null, is_string($mainField) ? $mainField : null);

        if (!$isMainFieldListable || !$canReadMainField) {
            // Default to 'documentId' if the actual main field shouldn't be displayed
            return 'documentId';
        }

        // Edge cases

        // 1. Enforce 'name' as the main field for users and permissions' roles
        if ($model->uid === 'plugin::users-permissions.role') {
            return 'name';
        }

        return (string) $mainField;
    }

    /**
     * All relations sent to this function should have the same status or no status
     *
     * @param list<array<string, mixed>> $relations
     * @return list<array<string, mixed>>
     */
    private function addStatusToRelations(string $targetUid, array $relations): array
    {
        if (!ContentTypes::hasDraftAndPublish($this->strapi->getModel($targetUid))) {
            return $relations;
        }

        $documentMetadata = Utils::getService($this->strapi, 'document-metadata');

        if ($relations === []) {
            return $relations;
        }

        $firstRelation = $relations[0];

        $filters = [
            'documentId' => ['$in' => array_map(static fn (array $r): mixed => $r['documentId'] ?? null, $relations)],
            // NOTE: find the "opposite" status
            'publishedAt' => ($firstRelation['publishedAt'] ?? null) !== null ? ['$null' => true] : ['$notNull' => true],
        ];

        $availableStatus = $this->strapi->db()->query($targetUid)->findMany([
            'select' => ['id', 'documentId', 'locale', 'updatedAt', 'createdAt', 'publishedAt'],
            'filters' => $filters,
        ]);

        $statusByDocumentId = DocumentStatus::indexByDocumentId($availableStatus);

        return array_map(static function (array $relation) use ($statusByDocumentId, $documentMetadata): array {
            $candidates = $statusByDocumentId[(string) ($relation['documentId'] ?? '')] ?? [];
            $availableStatuses = !empty($relation['locale'])
                ? array_values(array_filter($candidates, static fn (array $c): bool => ($c['locale'] ?? null) === $relation['locale']))
                : $candidates;

            return [
                ...$relation,
                'status' => $documentMetadata->getStatus($relation, $availableStatuses),
            ];
        }, $relations);
    }

    /** @return array<string, true> */
    private function getPublishedAtClause(?string $status, string $uid): array
    {
        $model = $this->strapi->getModel($uid);

        /**
         * If dp is disabled, ignore the filter
         */
        if ($model === null || !ContentTypes::hasDraftAndPublish($model)) {
            return [];
        }

        // Prioritize the draft status in case it's not provided
        return $status === 'published' ? ['$notNull' => true] : ['$null' => true];
    }

    private function isLocalized(?Schema $model): bool
    {
        if ($this->strapi->hasPlugin('i18n')) {
            $i18nContentTypes = $this->strapi->plugin('i18n')->service('content-types');
            if (method_exists($i18nContentTypes, 'isLocalizedContentType')) {
                return (bool) $i18nContentTypes->isLocalizedContentType($model);
            }
        }

        return $this->strapi->localization()->isLocalizedContentType($model);
    }

    /** @return array{locale: mixed, isSourceLocalized: bool, isTargetLocalized: bool} */
    private function validateLocale(string $sourceUid, string $targetUid, mixed $locale = null): array
    {
        $sourceModel = $this->strapi->getModel($sourceUid);
        $targetModel = $this->strapi->getModel($targetUid);

        $isSourceLocalized = $this->isLocalized($sourceModel);
        $isTargetLocalized = $this->isLocalized($targetModel);

        return [
            'locale' => $locale,
            'isSourceLocalized' => $isSourceLocalized,
            'isTargetLocalized' => $isTargetLocalized,
        ];
    }

    /** @return array{status: string|null} */
    private function validateStatus(string $sourceUid, mixed $status = null): array
    {
        $sourceModel = $this->strapi->getModel($sourceUid);

        $isSourceDP = ContentTypes::hasDraftAndPublish($sourceModel);

        // Default to draft if not set
        if (!$isSourceDP && $sourceModel?->modelType === 'contentType') {
            return ['status' => null];
        }

        return match ($status) {
            'published' => ['status' => 'published'],
            // Assign to draft if the status is not valid
            default => ['status' => 'draft'],
        };
    }

    /**
     * Returns the request info, or null when the response was already set (forbidden).
     *
     * @return array<string, mixed>|null
     */
    public function extractAndValidateRequestInfo(Context $ctx, mixed $id = null): ?array
    {
        $userAbility = $ctx->state()->get('userAbility');
        $model = (string) $ctx->param('model');
        $targetField = (string) $ctx->param('targetField');
        $query = $ctx->query();

        $sourceSchema = $this->strapi->getModel($model);
        if ($sourceSchema === null) {
            throw new ValidationError("The model {$model} doesn't exist");
        }

        $attribute = $sourceSchema->attributes[$targetField] ?? null;
        if ($attribute === null || ($attribute['type'] ?? null) !== 'relation') {
            throw new ValidationError("The relational field {$targetField} doesn't exist on {$model}");
        }

        $sourceUid = $model;
        $targetUid = (string) ($attribute['target'] ?? '');

        ['locale' => $locale, 'isSourceLocalized' => $isSourceLocalized, 'isTargetLocalized' => $isTargetLocalized] = $this->validateLocale(
            $sourceUid,
            $targetUid,
            $query['locale'] ?? null,
        );
        ['status' => $status] = $this->validateStatus($sourceUid, $query['status'] ?? null);

        $permissionChecker = Utils::getService($this->strapi, 'permission-checker')->create([
            'userAbility' => $userAbility,
            'model' => $model,
        ]);

        $isComponent = $sourceSchema->modelType === 'component';
        if (!$isComponent) {
            if ($permissionChecker->cannot('read', null, $targetField)) {
                $ctx->forbidden();

                return null;
            }
        }

        $entryId = null;

        if ($id !== null && $id !== '' && $id !== 0) {
            $where = [];

            if (!$isComponent) {
                $where['documentId'] = $id;

                if ($status !== null) {
                    $where['publishedAt'] = $this->getPublishedAtClause($status, $sourceUid);
                }

                if ($locale !== null && $locale !== '' && $isSourceLocalized) {
                    $where['locale'] = $locale;
                }
            } else {
                // If the source is a component, we only need to filter by the
                // component's entity id
                $where['id'] = $id;
            }

            $permissionQuery = $permissionChecker->sanitizedQuery($query, 'read');
            $populate = Utils::getService($this->strapi, 'populate-builder')($model)
                ->populateFromQuery($permissionQuery)
                ->build();

            $currentEntity = $this->strapi->db()->query($model)->findOne(array_filter([
                'where' => $where,
                'populate' => $populate,
            ], static fn (mixed $value): bool => $value !== null));

            // We need to check if the entity exists
            // and if the user has the permission to read it in this way
            // There may be multiple entities (publication states) under this
            // documentId + locale. We only need to check if one exists
            if ($currentEntity === null) {
                throw new NotFoundError();
            }

            if (!$isComponent) {
                if ($permissionChecker->cannot('read', $currentEntity, $targetField)) {
                    throw new ForbiddenError();
                }
            }

            $entryId = $currentEntity['id'];
        }

        $sourceModel = ContentTypes::toArray($sourceSchema);
        $modelConfig = $isComponent
            ? Utils::getService($this->strapi, 'components')->findConfiguration($sourceModel)
            : Utils::getService($this->strapi, 'content-types')->findConfiguration($sourceModel);

        $targetSchema = $this->strapi->getModel($targetUid);
        if ($targetSchema === null) {
            throw new ValidationError("The model {$targetUid} doesn't exist");
        }

        $mainField = $modelConfig['metadatas'][$targetField]['edit']['mainField'] ?? null;
        $mainField = $mainField !== null && $mainField !== '' ? $mainField : 'id';
        $mainField = $this->sanitizeMainField($targetSchema, $mainField, $userAbility);

        $fieldsToSelect = array_values(array_unique([
            $mainField,
            ContentTypes::PUBLISHED_AT_ATTRIBUTE,
            ContentTypes::UPDATED_AT_ATTRIBUTE,
            'documentId',
        ]));

        if ($isTargetLocalized) {
            $fieldsToSelect[] = 'locale';
        }

        return [
            'entryId' => $entryId,
            'locale' => $locale,
            'status' => $status,
            'attribute' => $attribute,
            'fieldsToSelect' => $fieldsToSelect,
            'mainField' => $mainField,
            'source' => ['schema' => $sourceSchema, 'isLocalized' => $isSourceLocalized],
            'target' => ['schema' => $targetSchema, 'isLocalized' => $isTargetLocalized],
            'sourceSchema' => $sourceSchema,
            'targetSchema' => $targetSchema,
            'targetField' => $targetField,
        ];
    }

    /**
     * Used to find new relations to add in a relational field.
     *
     * Component and document relations are dealt a bit differently (they don't have a document_id).
     */
    public function findAvailable(Context $ctx): mixed
    {
        $requestQuery = $ctx->query();
        $id = $requestQuery['id'] ?? null;

        RelationsValidation::validateFindAvailable($requestQuery);

        $info = $this->extractAndValidateRequestInfo($ctx, $id);
        if ($info === null) {
            return null;
        }

        $locale = $info['locale'];
        $status = $info['status'];
        $targetField = $info['targetField'];
        $fieldsToSelect = $info['fieldsToSelect'];
        $mainField = $info['mainField'];
        /** @var Schema $sourceSchema */
        $sourceSchema = $info['source']['schema'];
        $sourceUid = $sourceSchema->uid;
        $sourceModelType = $sourceSchema->modelType;
        $isSourceLocalized = $info['source']['isLocalized'];
        /** @var Schema $targetSchema */
        $targetSchema = $info['target']['schema'];
        $targetUid = $targetSchema->uid;
        $isTargetLocalized = $info['target']['isLocalized'];

        $idsToOmit = $requestQuery['idsToOmit'] ?? null;
        $idsToInclude = $requestQuery['idsToInclude'] ?? null;
        // `_q` is the public query parameter name; keep the alias grep-able.
        $search = $requestQuery['_q'] ?? null;
        $query = array_diff_key($requestQuery, ['idsToOmit' => true, 'idsToInclude' => true, '_q' => true]);

        $permissionChecker = Utils::getService($this->strapi, 'permission-checker')->create([
            'userAbility' => $ctx->state()->get('userAbility'),
            'model' => $targetUid,
        ]);
        $permissionQuery = $permissionChecker->sanitizedQuery($query, 'read');

        $queryParams = [
            'sort' => $mainField,
            // cannot select other fields as the user may not have the permissions
            'fields' => $fieldsToSelect,
            ...$permissionQuery,
        ];

        // If no status is requested, we find all the draft relations and later update them
        // with the latest available status
        self::addFiltersClause($queryParams, [
            'publishedAt' => $this->getPublishedAtClause($status, $targetUid),
        ]);

        // We will only filter by locale if the target content type is localized
        $filterByLocale = $isTargetLocalized && $locale !== null && $locale !== '';
        if ($filterByLocale) {
            self::addFiltersClause($queryParams, ['locale' => $locale]);
        }

        if ($id !== null && $id !== '') {
            /**
             * Exclude the relations that are already related to the source
             *
             * We also optionally filter the target relations by the requested
             * status and locale if provided.
             */
            $subQuery = $this->strapi->db()->queryBuilder($sourceUid);

            // The alias refers to the DB table of the target content type model
            $alias = $subQuery->getAlias();

            $where = [
                "{$alias}.id" => ['$notNull' => true],
                "{$alias}.document_id" => ['$notNull' => true],
            ];

            /**
             * Content Types -> Specify document id
             * Components    -> Specify entity id (they don't have a document id)
             */
            if ($sourceModelType === 'contentType') {
                $where['document_id'] = $id;

                $sourcePublishedAt = $this->getPublishedAtClause($status, $sourceUid);
                if ($sourcePublishedAt !== []) {
                    $where['published_at'] = $sourcePublishedAt;
                }
            } else {
                $where['id'] = $id;
            }

            // Add the status and locale filters if they are provided
            $publishedAt = $this->getPublishedAtClause($status, $targetUid);
            if ($publishedAt !== []) {
                $where["{$alias}.published_at"] = $publishedAt;
            }

            // If target has localization we need to filter by locale
            if ($isTargetLocalized && $locale !== null && $locale !== '') {
                $where["{$alias}.locale"] = $locale;
            }

            if ($isSourceLocalized && $locale !== null && $locale !== '') {
                $where['locale'] = $locale;
            }

            /**
             * UI can provide a list of ids to omit,
             * those are the relations user set in the UI but has not persisted.
             * We don't want to include them in the available relations.
             */
            if (is_array($idsToInclude) && $idsToInclude !== []) {
                $where["{$alias}.id"]['$notIn'] = $idsToInclude;
            }

            $knexSubQuery = $subQuery
                ->where($where)
                ->join(['alias' => $alias, 'targetField' => $targetField])
                ->select("{$alias}.id")
                ->getSqlQuery();

            self::addFiltersClause($queryParams, [
                'id' => ['$notIn' => $knexSubQuery],
            ]);
        }

        /**
         * Apply a filter to the mainField based on the search query and filter operator
         * searching should be allowed only on mainField for permission reasons
         */
        if ($search !== null && $search !== '') {
            $filterOperator = $query['_filter'] ?? null;
            $filter = is_string($filterOperator) && Operators::isOperatorOfType('where', $filterOperator) ? $filterOperator : '$containsi';
            self::addFiltersClause($queryParams, [$mainField => [$filter => $search]]);
        }

        if (is_array($idsToOmit) && $idsToOmit !== []) {
            // If we have ids to omit, we should filter them out
            self::addFiltersClause($queryParams, [
                'id' => ['$notIn' => array_values(array_unique($idsToOmit))],
            ]);
        }

        $dbQuery = $this->strapi->get('query-params')->transform($targetUid, $queryParams);

        $res = $this->strapi->db()->query($targetUid)->findPage($dbQuery);

        $ctx->setBody([
            ...$res,
            'results' => $this->addStatusToRelations($targetUid, $res['results']),
        ]);

        return null;
    }

    public function findExisting(Context $ctx): mixed
    {
        $userAbility = $ctx->state()->get('userAbility');
        $id = $ctx->param('id');
        $requestQuery = $ctx->query();

        RelationsValidation::validateFindExisting($requestQuery);

        $info = $this->extractAndValidateRequestInfo($ctx, $id);
        if ($info === null) {
            return null;
        }

        $entryId = $info['entryId'];
        $attribute = $info['attribute'];
        $targetField = $info['targetField'];
        $fieldsToSelect = $info['fieldsToSelect'];
        $status = $info['status'];
        /** @var Schema $sourceSchema */
        $sourceSchema = $info['source']['schema'];
        /** @var Schema $targetSchema */
        $targetSchema = $info['target']['schema'];

        $sourceUid = $sourceSchema->uid;
        $targetUid = $targetSchema->uid;

        $permissionQuery = Utils::getService($this->strapi, 'permission-checker')
            ->create(['userAbility' => $userAbility, 'model' => $targetUid])
            ->sanitizedQuery(['fields' => $fieldsToSelect], 'read');

        /**
         * loadPages can not be used for single relations,
         * this unifies the loading regardless of it's type
         *
         * NOTE: Relations need to be loaded using any db.query method
         *       to ensure the proper ordering is applied
         */
        $dbQuery = $this->strapi->db()->query($sourceUid);
        $loadRelations = RelationsUtils::isAnyToMany($attribute)
            ? static fn (array $entity, string $field, array $params): array => $dbQuery->loadPages($entity, $field, $params)
            : static function (array $entity, string $field, array $params) use ($dbQuery): array {
                $res = $dbQuery->load($entity, $field, $params);

                // Ensure response is an array
                return ['results' => is_array($res) && $res !== [] ? [$res] : []];
            };

        $filters = [];

        if (($sourceSchema->options['draftAndPublish'] ?? false) || $sourceSchema->modelType === 'component') {
            if ($targetSchema->options['draftAndPublish'] ?? false) {
                if ($status === 'published') {
                    $filters['publishedAt'] = ['$notNull' => true];
                } else {
                    $filters['publishedAt'] = ['$null' => true];
                }
            }
        } elseif ($targetSchema->options['draftAndPublish'] ?? false) {
            // NOTE: we must return the drafts as some targets might not have a published version yet
            $filters['publishedAt'] = ['$null' => true];
        }

        /**
         * If user does not have access to specific relations (custom conditions),
         * only the ids of the relations are returned.
         *
         * - First query loads all the ids.
         * - Second one also loads the main field, and excludes forbidden relations.
         *
         * The response contains the union of the two queries.
         */
        $res = $loadRelations(['id' => $entryId], $targetField, array_filter([
            'select' => ['id', 'documentId', 'locale', 'publishedAt', 'updatedAt'],
            'ordering' => 'desc',
            'page' => $requestQuery['page'] ?? null,
            'pageSize' => $requestQuery['pageSize'] ?? null,
            'filters' => $filters,
        ], static fn (mixed $value): bool => $value !== null));

        /**
         * Add all ids to load in permissionQuery
         * If any of the relations are not accessible, the permissionQuery will exclude them
         */
        $loadedIds = array_map(static fn (array $item): mixed => $item['id'] ?? null, $res['results']);
        self::addFiltersClause($permissionQuery, ['id' => ['$in' => $loadedIds]]);

        /**
         * Load the relations with the main field, the sanitized permission query
         * will exclude the relations the user does not have access to.
         *
         * Pagination is not necessary as the permissionQuery contains the ids to load.
         */
        $sanitizedRes = $loadRelations(['id' => $entryId], $targetField, [
            ...$this->strapi->get('query-params')->transform($targetUid, $permissionQuery),
            'ordering' => 'desc',
        ]);

        // NOTE: the order is very important to make sure sanitized relations are kept in priority
        $relationsUnion = [];
        $seenIds = [];
        foreach ([...$sanitizedRes['results'], ...$res['results']] as $relation) {
            $key = \Strapi\Utils\Primitives\Strings::stringify($relation['id'] ?? null);
            if (isset($seenIds[$key])) {
                continue;
            }
            $seenIds[$key] = true;
            $relationsUnion[] = $relation;
        }

        $ctx->setBody([
            'pagination' => $res['pagination'] ?? [
                'page' => 1,
                'pageCount' => 1,
                'pageSize' => 10,
                'total' => count($relationsUnion),
            ],
            'results' => $this->addStatusToRelations($targetUid, $relationsUnion),
        ]);

        return null;
    }
}
