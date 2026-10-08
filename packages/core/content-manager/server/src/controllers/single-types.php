<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Controllers\Utils\Metadata;
use Strapi\ContentManager\Controllers\Validation\Dimensions;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Services\Utils\DraftRelations;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\SetCreatorFields;

/** Port of server/src/controllers/single-types.ts. */
final class SingleTypes
{
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

    /** @return array<string, mixed> */
    private static function body(Context $ctx): array
    {
        $body = $ctx->requestBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array{availableLocales?: bool, availableStatus?: bool} $opts
     * @return array<string, mixed>
     * @param PermissionChecker $permissionChecker
     */
    private function format(object $permissionChecker, string $model, mixed $document, array $opts = []): array
    {
        return Metadata::formatDocumentWithMetadata($this->strapi, $permissionChecker, $model, is_array($document) ? $document : null, $opts);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function buildPopulateFromQuery(array $query, string $model): ?array
    {
        return Utils::getService($this->strapi, 'populate-builder')($model)
            ->populateFromQuery($query)
            ->populateDeep(INF)
            ->countRelations()
            ->withPopulateOverride(Populate::getPopulateForLocalizations($this->strapi, $model))
            ->build();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     * @return array<string, mixed>|null
     */
    private function findDocument(array $query, string $uid, array $opts = []): ?array
    {
        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $populate = $this->buildPopulateFromQuery($query, $uid);

        $documents = $documentManager->findMany([...$opts, 'populate' => $populate], $uid);

        // Return the first document found
        return $documents[0] ?? null;
    }

    /**
     * @param array{populate?: mixed} $opts
     * @return array<string, mixed>|null
     */
    private function createOrUpdateDocument(Context $ctx, array $opts = []): ?array
    {
        $user = $ctx->state()->get('user');
        $model = (string) $ctx->param('model');
        $body = self::body($ctx);
        $query = $ctx->query();

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('create') && $permissionChecker->cannot('update')) {
            throw new ForbiddenError();
        }

        $sanitizedQuery = $permissionChecker->sanitizedQuery($query, 'update');

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

        // Load document version to update
        $documentVersion = $this->findDocument($sanitizedQuery, $model, ['locale' => $locale, 'status' => 'draft']);
        // Find the first document to check if it exists
        $otherDocumentVersion = $this->strapi->db()->query($model)->findOne(['select' => ['documentId']]);

        $documentId = $otherDocumentVersion['documentId'] ?? null;

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

        if ($documentId === null) {
            return $documentManager->create($model, [
                'data' => $sanitizedBody,
                ...$sanitizedQuery,
                'locale' => $locale,
            ]);
        }

        return $documentManager->update((string) $documentId, $model, [
            'data' => $sanitizedBody,
            'populate' => $opts['populate'] ?? null,
            'locale' => $locale,
        ]);
    }

    public function find(Context $ctx): mixed
    {
        $model = (string) $ctx->param('model');
        $query = $ctx->query();

        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($query, 'read');
        ['locale' => $locale, 'status' => $status] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $query, $model);

        $version = $this->findDocument($permissionQuery, $model, ['locale' => $locale, 'status' => $status]);

        // allow user with create permission to know a single type is not created
        if ($version === null) {
            if ($permissionChecker->cannot('create')) {
                $ctx->forbidden();

                return null;
            }
            // Check if document exists
            $document = $this->strapi->db()->query($model)->findOne([]);

            if ($document === null) {
                $ctx->notFound();

                return null;
            }

            // If the requested locale doesn't exist, return an empty response
            ['meta' => $meta] = $this->format(
                $permissionChecker,
                $model,
                ['documentId' => $document['documentId'] ?? null, 'locale' => $locale, 'publishedAt' => null],
                ['availableLocales' => true, 'availableStatus' => false],
            );
            $ctx->setBody(['data' => new \stdClass(), 'meta' => $meta]);

            return null;
        }

        if ($permissionChecker->cannot('read', $version)) {
            $ctx->forbidden();

            return null;
        }

        $sanitizedDocument = $permissionChecker->sanitizeOutput($version);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument));

        return null;
    }

    public function createOrUpdate(Context $ctx): mixed
    {
        $model = (string) $ctx->param('model');

        $permissionChecker = $this->permissionChecker($ctx, $model);

        $document = $this->createOrUpdateDocument($ctx);

        $sanitizedDocument = $permissionChecker->sanitizeOutput($document);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument));

        return null;
    }

    public function delete(Context $ctx): mixed
    {
        $model = (string) $ctx->param('model');
        $query = $ctx->query();

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('delete')) {
            $ctx->forbidden();

            return null;
        }

        $sanitizedQuery = $permissionChecker->sanitizedQuery($query, 'delete');
        $populate = $this->buildPopulateFromQuery($sanitizedQuery, $model);

        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $query, $model);
        $documentLocales = $documentManager->findLocales(null, $model, [
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

        $deletedEntity = $documentManager->delete((string) $documentLocales[0]['documentId'], $model, [
            'locale' => $locale,
        ]);

        $sanitized = $permissionChecker->sanitizeOutput($deletedEntity);
        $ctx->setBody($sanitized === [] ? new \stdClass() : $sanitized);

        return null;
    }

    public function publish(Context $ctx): mixed
    {
        $model = (string) $ctx->param('model');
        $body = self::body($ctx);
        $query = $ctx->query();

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('publish')) {
            $ctx->forbidden();

            return null;
        }

        $publishedDocument = $this->strapi->db()->transaction(function () use ($ctx, $model, $body, $query, $documentManager, $permissionChecker): mixed {
            $sanitizedQuery = $permissionChecker->sanitizedQuery($query, 'publish');
            $populate = $this->buildPopulateFromQuery($sanitizedQuery, $model);
            ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

            // Find the existing document
            $document = $this->findDocument($sanitizedQuery, $model, ['locale' => $locale, 'status' => 'draft']);

            // If document exists and user can update it, update it before publishing
            $shouldUpdate = $document !== null && $permissionChecker->can('update', $document);
            // If document doesn't exist and user can create it, create it before publishing
            $shouldCreate = $document === null && $permissionChecker->can('create');
            if ($shouldUpdate || $shouldCreate) {
                $document = $this->createOrUpdateDocument($ctx, ['populate' => $populate]);
            } elseif ($document === null) {
                // Document doesn't exist and user can't create it
                throw new ForbiddenError();
            }

            // If document doesn't exist, throw an error
            if ($document === null) {
                throw new NotFoundError();
            }

            if ($permissionChecker->cannot('publish', $document)) {
                throw new ForbiddenError();
            }

            $publishResult = $documentManager->publish((string) $document['documentId'], $model, ['locale' => $locale]);

            return $publishResult[0] ?? null;
        });

        $sanitizedDocument = $permissionChecker->sanitizeOutput($publishedDocument);
        $ctx->setBody($this->format($permissionChecker, $model, $sanitizedDocument));

        return null;
    }

    public function unpublish(Context $ctx): mixed
    {
        $model = (string) $ctx->param('model');
        $body = self::body($ctx);
        $discardDraft = $body['discardDraft'] ?? null;
        unset($body['discardDraft']);
        $query = $ctx->query();

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

        $sanitizedQuery = $permissionChecker->sanitizedQuery($query, 'unpublish');
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

        $document = $this->findDocument($sanitizedQuery, $model, ['locale' => $locale]);

        if ($document === null) {
            $ctx->notFound();

            return null;
        }

        if ($permissionChecker->cannot('unpublish', $document)) {
            $ctx->forbidden();

            return null;
        }

        if (!empty($discardDraft) && $permissionChecker->cannot('discard', $document)) {
            $ctx->forbidden();

            return null;
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
        $model = (string) $ctx->param('model');
        $body = self::body($ctx);
        $query = $ctx->query();

        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('discard')) {
            $ctx->forbidden();

            return null;
        }

        $sanitizedQuery = $permissionChecker->sanitizedQuery($query, 'discard');
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $body, $model);

        $document = $this->findDocument($sanitizedQuery, $model, ['locale' => $locale, 'status' => 'published']);

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

    public function countDraftRelations(Context $ctx): mixed
    {
        $model = (string) $ctx->param('model');
        $query = $ctx->query();
        $documentManager = Utils::getService($this->strapi, 'document-manager');
        $permissionChecker = $this->permissionChecker($ctx, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return null;
        }

        $permissionQuery = $permissionChecker->sanitizedQuery($query, 'read');
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $query, $model);

        $document = $this->findDocument($permissionQuery, $model, ['locale' => $locale]);

        if ($document === null) {
            // The single type may simply not have a version in the requested locale yet.
            // Check every existing locale/status version — findLocales returns one row
            // per locale AND per publication state — before deciding it truly doesn't exist.
            $populate = $this->buildPopulateFromQuery($permissionQuery, $model);
            $versions = $documentManager->findLocales(null, $model, ['populate' => $populate]);

            if ($versions === []) {
                $ctx->notFound();

                return null;
            }

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

            return ['data' => DraftRelations::EMPTY_DRAFT_RELATION_COUNTS];
        }

        if ($permissionChecker->cannot('read', $document)) {
            $ctx->forbidden();

            return null;
        }

        $counts = $documentManager->countDraftRelations((string) $document['documentId'], $model, is_string($locale) ? $locale : null);

        return [
            'data' => $counts,
        ];
    }
}
