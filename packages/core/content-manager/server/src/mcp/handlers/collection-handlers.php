<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Handlers;

use Strapi\ContentManager\Controllers\Utils\DocumentStatus;
use Strapi\ContentManager\Controllers\Utils\Metadata;
use Strapi\ContentManager\Controllers\Validation\Dimensions;
use Strapi\ContentManager\Mcp\Permissions;
use Strapi\ContentManager\Mcp\Sanitizers\ShapeRelations;
use Strapi\ContentManager\Mcp\Utils;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Utils\Utils as CmUtils;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\SetCreatorFields;

/**
 * Port of server/src/mcp/handlers/collection-handlers.ts: the collection-type tool handlers. Each
 * `createCollection*Handler($uid)` returns the tool's `createHandler(Strapi, context)`, which
 * returns the handler `fn(['args' => ...]): result`. Arguments are validated by the MCP server
 * before the handler runs.
 *
 * @phpstan-type McpHandlerContext array{userAbility: \Strapi\Permissions\Engine\Abilities\Ability, user?: mixed}
 */
final class CollectionHandlers
{
    /**
     * JS drops `undefined` keys from a query: only the given values are kept.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function defined(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $context
     * @return PermissionChecker
     */
    public static function checker(Strapi $strapi, array $context, string $uid): object
    {
        return CmUtils::getService($strapi, 'permission-checker')->create(['userAbility' => $context['userAbility'] ?? null, 'model' => $uid]);
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionListHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentMetadata = CmUtils::getService($strapi, 'document-metadata');
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('read')) {
                throw new ForbiddenError();
            }

            $query = self::defined([
                'page' => $args['page'] ?? null,
                'pageSize' => $args['pageSize'] ?? null,
                'sort' => $args['sort'] ?? null,
                'filters' => $args['filters'] ?? null,
            ]);

            $permissionQuery = $permissionChecker->sanitizedQuery($query, 'read');

            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)
                ->populateFromQuery($permissionQuery)
                ->populateDeep(1)
                ->withPopulateOverride(Populate::getPopulateForLocalizations($strapi, $uid))
                ->build();

            ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null, 'status' => $args['status'] ?? null]), $uid);

            ['results' => $documents, 'pagination' => $pagination] = $documentManager->findPage(
                [...$permissionQuery, 'populate' => $populate, 'locale' => $locale, 'status' => $status],
                $uid,
            );

            $hasDraftAndPublish = ContentTypes::hasDraftAndPublish($strapi->getModel($uid));
            $statusByDocumentId = $hasDraftAndPublish
                ? DocumentStatus::indexByDocumentId($documentMetadata->getManyAvailableStatus($uid, $documents))
                : [];

            // Calculate, then strip: the status reads publishedAt/updatedAt, so it runs before
            // relation shaping reduces the document at the output boundary.
            $results = [];
            foreach ($documents as $document) {
                $document = $permissionChecker->sanitizeOutput($document);
                if (is_array($document)) {
                    $availableStatuses = $statusByDocumentId[(string) ($document['documentId'] ?? '')] ?? [];
                    $document['status'] = $documentMetadata->getStatus($document, $availableStatuses);
                    $document = ShapeRelations::shapeRelationsForMcp($strapi, $uid, $document);
                }
                $results[] = $document;
            }

            return Utils::ok(['results' => $results, 'pagination' => $pagination]);
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionGetHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentId = (string) ($args['documentId'] ?? '');
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('read')) {
                throw new ForbiddenError();
            }

            $dimensions = self::defined(['locale' => $args['locale'] ?? null, 'status' => $args['status'] ?? null]);
            $permissionQuery = $permissionChecker->sanitizedQuery($dimensions, 'read');

            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)
                ->populateFromQuery($permissionQuery)
                ->populateDeep(INF)
                ->withPopulateOverride(Populate::getPopulateForLocalizations($strapi, $uid))
                ->build();

            ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($strapi, $dimensions, $uid);

            $version = $documentManager->findOne($documentId, $uid, ['populate' => $populate, 'locale' => $locale, 'status' => $status]);

            if ($version === null) {
                if (!$documentManager->exists($uid, $documentId)) {
                    throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
                }

                ['meta' => $meta] = Metadata::formatDocumentWithMetadata(
                    $strapi,
                    $permissionChecker,
                    $uid,
                    ['documentId' => $documentId, 'locale' => $locale, 'publishedAt' => null],
                    ['availableLocales' => true, 'availableStatus' => false],
                );

                return Utils::ok(['data' => new \stdClass(), 'meta' => $meta]);
            }

            if ($permissionChecker->cannot('read', $version)) {
                throw new ForbiddenError();
            }

            return Utils::ok(Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $version));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionCreateHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('create')) {
                throw new ForbiddenError();
            }

            $sanitizedData = SetCreatorFields::apply(self::arrayOf($permissionChecker->sanitizeCreateInput($args['data'] ?? [])), ['user' => self::user($context)]);

            ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null]), $uid);

            $result = $strapi->db()->transaction(static function () use ($strapi, $documentManager, $permissionChecker, $uid, $sanitizedData, $locale, $status): array {
                $document = $documentManager->create($uid, ['data' => $sanitizedData, 'locale' => $locale, 'status' => $status]);

                return Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $document, ['availableLocales' => false, 'availableStatus' => false]);
            });

            return Utils::ok(self::arrayOf($result));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionUpdateHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentId = (string) ($args['documentId'] ?? '');
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('update')) {
                throw new ForbiddenError();
            }

            $permissionQuery = $permissionChecker->sanitizedQuery(self::defined(['locale' => $args['locale'] ?? null]), 'update');
            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)->populateFromQuery($permissionQuery)->build();

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null]), $uid);

            $documentVersion = $documentManager->findOne($documentId, $uid, ['populate' => $populate, 'locale' => $locale, 'status' => 'draft']);
            $documentExists = $documentManager->exists($uid, $documentId);

            if (!$documentExists) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
            }

            // If version is not found but document exists, the intent is to create a new locale
            if ($documentVersion !== null) {
                if ($permissionChecker->cannot('update', $documentVersion)) {
                    throw new ForbiddenError();
                }
            } elseif ($permissionChecker->cannot('create')) {
                throw new ForbiddenError();
            }

            $sanitized = $documentVersion !== null
                ? $permissionChecker->sanitizeUpdateInput($documentVersion)($args['data'] ?? [])
                : $permissionChecker->sanitizeCreateInput($args['data'] ?? []);
            $sanitizedData = SetCreatorFields::apply(self::arrayOf($sanitized), ['user' => self::user($context), 'isEdition' => $documentVersion !== null]);

            $result = $strapi->db()->transaction(static function () use ($strapi, $documentManager, $permissionChecker, $uid, $documentVersion, $documentId, $sanitizedData, $locale): array {
                $updatedDocument = $documentManager->update((string) ($documentVersion['documentId'] ?? $documentId), $uid, ['data' => $sanitizedData, 'locale' => $locale]);

                return Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $updatedDocument);
            });

            return Utils::ok(self::arrayOf($result));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionDeleteHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentId = (string) ($args['documentId'] ?? '');
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('delete')) {
                throw new ForbiddenError();
            }

            $permissionQuery = $permissionChecker->sanitizedQuery(self::defined(['locale' => $args['locale'] ?? null]), 'delete');
            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)->populateFromQuery($permissionQuery)->build();

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null]), $uid);

            $localeForQuery = Permissions::isContentTypeLocalized($strapi, $uid) ? $locale : null;

            $documentLocales = $documentManager->findLocales($documentId, $uid, self::defined(['populate' => $populate, 'locale' => $localeForQuery]));

            if ($documentLocales === []) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
            }

            foreach ($documentLocales as $document) {
                if ($permissionChecker->cannot('delete', $document)) {
                    throw new ForbiddenError();
                }
            }

            $result = $documentManager->delete($documentId, $uid, self::defined(['locale' => $localeForQuery]));
            // Delete returns `{ data }` without metadata — no status to compute, so sanitize + shape only.
            $sanitizedResult = $permissionChecker->sanitizeOutput($result);
            $shapedResult = is_array($sanitizedResult) ? ShapeRelations::shapeRelationsForMcp($strapi, $uid, $sanitizedResult) : $sanitizedResult;

            return Utils::ok(['data' => $shapedResult === [] ? new \stdClass() : $shapedResult]);
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionPublishHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentId = (string) ($args['documentId'] ?? '');
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('publish')) {
                throw new ForbiddenError();
            }

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null]), $uid);

            $publishedDocument = $strapi->db()->transaction(static function () use ($documentManager, $permissionChecker, $uid, $documentId, $locale): mixed {
                if (!$documentManager->exists($uid, $documentId)) {
                    throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
                }

                $document = $documentManager->findOne($documentId, $uid, ['locale' => $locale, 'status' => 'draft']);

                if ($document === null) {
                    throw new NotFoundError(Constants::MCP_NOT_FOUND_LOCALE);
                }

                if ($permissionChecker->cannot('publish', $document)) {
                    throw new ForbiddenError();
                }

                $publishResult = $documentManager->publish((string) $document['documentId'], $uid, ['locale' => $locale]);

                if ($publishResult === []) {
                    throw new NotFoundError(Constants::MCP_NOT_FOUND_OR_PUBLISHED);
                }

                return $publishResult[0];
            });

            return Utils::ok(Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $publishedDocument));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionUnpublishHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentId = (string) ($args['documentId'] ?? '');
            $discardDraft = ($args['discardDraft'] ?? null) === true;
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('unpublish')) {
                throw new ForbiddenError();
            }
            if ($discardDraft && $permissionChecker->cannot('discard')) {
                throw new ForbiddenError();
            }

            $permissionQuery = $permissionChecker->sanitizedQuery(self::defined(['locale' => $args['locale'] ?? null]), 'unpublish');
            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)->populateFromQuery($permissionQuery)->build();

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null]), $uid);

            $document = $documentManager->findOne($documentId, $uid, ['populate' => $populate, 'locale' => $locale, 'status' => 'published']);

            if ($document === null) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
            }
            if ($permissionChecker->cannot('unpublish', $document)) {
                throw new ForbiddenError();
            }
            if ($discardDraft && $permissionChecker->cannot('discard', $document)) {
                throw new ForbiddenError();
            }

            $unpublishedDocument = $strapi->db()->transaction(static function () use ($documentManager, $uid, $document, $locale, $discardDraft): mixed {
                if ($discardDraft) {
                    $documentManager->discardDraft((string) $document['documentId'], $uid, ['locale' => $locale]);
                }

                return $documentManager->unpublish((string) $document['documentId'], $uid, ['locale' => $locale]);
            });

            return Utils::ok(Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $unpublishedDocument));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createCollectionDiscardDraftHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentId = (string) ($args['documentId'] ?? '');
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('discard')) {
                throw new ForbiddenError();
            }

            $permissionQuery = $permissionChecker->sanitizedQuery(self::defined(['locale' => $args['locale'] ?? null]), 'discard');
            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)->populateFromQuery($permissionQuery)->build();

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, self::defined(['locale' => $args['locale'] ?? null]), $uid);

            $document = $documentManager->findOne($documentId, $uid, ['populate' => $populate, 'locale' => $locale, 'status' => 'published']);

            if ($document === null) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
            }
            if ($permissionChecker->cannot('discard', $document)) {
                throw new ForbiddenError();
            }

            $discarded = $documentManager->discardDraft((string) $document['documentId'], $uid, ['locale' => $locale]);

            return Utils::ok(Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $discarded));
        };
    }

    /** @param array<string, mixed> $context */
    public static function user(array $context): mixed
    {
        return $context['user'] ?? ['id' => null];
    }

    /** @return array<string, mixed> */
    public static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
