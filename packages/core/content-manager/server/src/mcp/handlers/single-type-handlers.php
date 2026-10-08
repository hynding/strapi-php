<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Handlers;

use Strapi\ContentManager\Controllers\Utils\Metadata;
use Strapi\ContentManager\Controllers\Validation\Dimensions;
use Strapi\ContentManager\Mcp\Permissions;
use Strapi\ContentManager\Mcp\Sanitizers\ShapeRelations;
use Strapi\ContentManager\Mcp\Utils;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Utils\Utils as CmUtils;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\SetCreatorFields;

/**
 * Port of server/src/mcp/handlers/single-type-handlers.ts: the single-type tool handlers (same
 * shape as CollectionHandlers).
 */
final class SingleTypeHandlers
{
    /**
     * @param array<string, mixed> $context
     * @return PermissionChecker
     */
    private static function checker(Strapi $strapi, array $context, string $uid): object
    {
        return CollectionHandlers::checker($strapi, $context, $uid);
    }

    /**
     * `documentManager.findMany(query, uid).then(docs => docs[0])`
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private static function first(Strapi $strapi, array $query, string $uid): ?array
    {
        $docs = CmUtils::getService($strapi, 'document-manager')->findMany($query, $uid);
        $first = $docs[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Core write logic of the single-type write handler: creates the document when none exists,
     * updates the existing draft otherwise.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function singleCreateOrUpdate(Strapi $strapi, string $uid, array $context, array $args): array
    {
        $documentManager = CmUtils::getService($strapi, 'document-manager');
        $permissionChecker = self::checker($strapi, $context, $uid);

        if ($permissionChecker->cannot('create') && $permissionChecker->cannot('update')) {
            throw new ForbiddenError();
        }

        $sanitizedQuery = $permissionChecker->sanitizedQuery(CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), 'update');
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), $uid);

        $populate = CmUtils::getService($strapi, 'populate-builder')($uid)
            ->populateFromQuery($sanitizedQuery)
            ->populateDeep(INF)
            ->withPopulateOverride(Populate::getPopulateForLocalizations($strapi, $uid))
            ->build();

        $documentVersion = self::first($strapi, [...$sanitizedQuery, 'populate' => $populate, 'locale' => $locale, 'status' => 'draft'], $uid);
        $otherDocumentVersion = $strapi->db()->query($uid)->findOne(['select' => ['documentId']]);
        $documentId = is_array($otherDocumentVersion) ? ($otherDocumentVersion['documentId'] ?? null) : null;

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
        $sanitizedData = SetCreatorFields::apply(CollectionHandlers::arrayOf($sanitized), ['user' => CollectionHandlers::user($context), 'isEdition' => $documentVersion !== null]);

        $formatted = $strapi->db()->transaction(static function () use ($strapi, $documentManager, $permissionChecker, $uid, $documentId, $sanitizedData, $sanitizedQuery, $populate, $locale): array {
            $doc = $documentId === null
                ? $documentManager->create($uid, [...$sanitizedQuery, 'data' => $sanitizedData, 'locale' => $locale])
                : $documentManager->update((string) $documentId, $uid, ['data' => $sanitizedData, 'populate' => $populate, 'locale' => $locale]);

            return Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $doc);
        });

        return Utils::ok(CollectionHandlers::arrayOf($formatted));
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createSingleGetHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('read')) {
                throw new ForbiddenError();
            }

            $dimensions = CollectionHandlers::defined(['locale' => $args['locale'] ?? null, 'status' => $args['status'] ?? null]);
            $permissionQuery = $permissionChecker->sanitizedQuery($dimensions, 'read');
            ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($strapi, $dimensions, $uid);

            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)
                ->populateFromQuery($permissionQuery)
                ->populateDeep(INF)
                ->withPopulateOverride(Populate::getPopulateForLocalizations($strapi, $uid))
                ->build();

            $version = self::first($strapi, [...$permissionQuery, 'populate' => $populate, 'locale' => $locale, 'status' => $status], $uid);

            if ($version === null) {
                if ($permissionChecker->cannot('create')) {
                    throw new ForbiddenError();
                }

                $document = $strapi->db()->query($uid)->findOne([]);

                if (!is_array($document)) {
                    throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
                }

                ['meta' => $meta] = Metadata::formatDocumentWithMetadata(
                    $strapi,
                    $permissionChecker,
                    $uid,
                    ['documentId' => $document['documentId'] ?? null, 'locale' => $locale, 'publishedAt' => null],
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
    public static function createSingleWriteHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static fn (array $params): array => self::singleCreateOrUpdate($strapi, $uid, $context, $params['args'] ?? []);
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createSingleDeleteHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('delete')) {
                throw new ForbiddenError();
            }

            $sanitizedQuery = $permissionChecker->sanitizedQuery(CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), 'delete');

            $populate = CmUtils::getService($strapi, 'populate-builder')($uid)
                ->populateFromQuery($sanitizedQuery)
                ->populateDeep(INF)
                ->withPopulateOverride(Populate::getPopulateForLocalizations($strapi, $uid))
                ->build();

            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), $uid);

            $localeForQuery = Permissions::isContentTypeLocalized($strapi, $uid) ? $locale : null;

            $documentLocales = $documentManager->findLocales(null, $uid, CollectionHandlers::defined(['populate' => $populate, 'locale' => $localeForQuery]));

            if ($documentLocales === []) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
            }

            foreach ($documentLocales as $document) {
                if ($permissionChecker->cannot('delete', $document)) {
                    throw new ForbiddenError();
                }
            }

            $deletedEntity = $documentManager->delete((string) ($documentLocales[0]['documentId'] ?? ''), $uid, CollectionHandlers::defined(['locale' => $localeForQuery]));

            $sanitizedResult = $permissionChecker->sanitizeOutput($deletedEntity);
            $shapedResult = is_array($sanitizedResult) ? ShapeRelations::shapeRelationsForMcp($strapi, $uid, $sanitizedResult) : $sanitizedResult;

            return Utils::ok(['data' => $shapedResult === [] ? new \stdClass() : $shapedResult]);
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createSinglePublishHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('publish')) {
                throw new ForbiddenError();
            }

            $publishedDocument = $strapi->db()->transaction(static function () use ($strapi, $documentManager, $permissionChecker, $uid, $args): mixed {
                $sanitizedQuery = $permissionChecker->sanitizedQuery(CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), 'publish');
                ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), $uid);

                $document = self::first($strapi, [...$sanitizedQuery, 'locale' => $locale, 'status' => 'draft'], $uid);

                if ($document === null) {
                    throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
                }
                if ($permissionChecker->cannot('publish', $document)) {
                    throw new ForbiddenError();
                }

                $publishResult = $documentManager->publish((string) $document['documentId'], $uid, ['locale' => $locale]);

                return $publishResult[0] ?? null;
            });

            return Utils::ok(Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $publishedDocument));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createSingleUnpublishHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $discardDraft = ($args['discardDraft'] ?? null) === true;
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('unpublish')) {
                throw new ForbiddenError();
            }
            if ($discardDraft && $permissionChecker->cannot('discard')) {
                throw new ForbiddenError();
            }

            $sanitizedQuery = $permissionChecker->sanitizedQuery(CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), 'unpublish');
            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), $uid);

            $document = self::first($strapi, [...$sanitizedQuery, 'locale' => $locale], $uid);

            if ($document === null) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_DOCUMENT);
            }
            if ($permissionChecker->cannot('unpublish', $document)) {
                throw new ForbiddenError();
            }
            if ($discardDraft && $permissionChecker->cannot('discard', $document)) {
                throw new ForbiddenError();
            }

            $result = $strapi->db()->transaction(static function () use ($strapi, $documentManager, $permissionChecker, $uid, $document, $locale, $discardDraft): array {
                if ($discardDraft) {
                    $documentManager->discardDraft((string) $document['documentId'], $uid, ['locale' => $locale]);
                }

                $unpublished = $documentManager->unpublish((string) $document['documentId'], $uid, ['locale' => $locale]);

                return Utils::sanitizeFormatShape($strapi, $permissionChecker, $uid, $unpublished);
            });

            return Utils::ok(CollectionHandlers::arrayOf($result));
        };
    }

    /** @return \Closure(Strapi, array<string, mixed>): \Closure */
    public static function createSingleDiscardDraftHandler(string $uid): \Closure
    {
        return static fn (Strapi $strapi, array $context): \Closure => static function (array $params) use ($strapi, $context, $uid): array {
            $args = $params['args'] ?? [];
            $documentManager = CmUtils::getService($strapi, 'document-manager');
            $permissionChecker = self::checker($strapi, $context, $uid);

            if ($permissionChecker->cannot('discard')) {
                throw new ForbiddenError();
            }

            $sanitizedQuery = $permissionChecker->sanitizedQuery(CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), 'discard');
            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($strapi, CollectionHandlers::defined(['locale' => $args['locale'] ?? null]), $uid);

            $document = self::first($strapi, [...$sanitizedQuery, 'locale' => $locale, 'status' => 'published'], $uid);

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
}
