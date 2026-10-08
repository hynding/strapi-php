<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Homepage\Services;

use Strapi\ContentManager\Services\DocumentMetadata;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/homepage/services/homepage.ts (`createHomepageService`).
 *
 * `getCountDocuments` runs upstream's raw knex queries as SQL on the DBAL connection.
 *
 * @phpstan-type ContentTypeConfiguration array{uid: string, settings: array{mainField: string}}
 * @phpstan-type ContentTypeMeta array{fields: list<string>, mainField: string, contentType: Schema, hasDraftAndPublish: bool, uid: string}
 */
final class Homepage
{
    private const int MAX_DOCUMENTS = 4;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createHomepageService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /**
     * Typed as `object` natively so that unit tests can register stubs.
     *
     * @return \Strapi\Admin\Services\Permission
     */
    private function adminPermissionService(): object
    {
        /** @var \Strapi\Admin\Services\Permission $service */
        $service = $this->strapi->service('admin::permission');

        return $service;
    }

    /** @return DocumentMetadata */
    private function metadataService(): object
    {
        /** @var DocumentMetadata $service */
        $service = $this->strapi->plugin('content-manager')->service('document-metadata');

        return $service;
    }

    private function getRegisteredContentType(string $uid): ?Schema
    {
        $contentType = $this->strapi->contentTypes()[$uid] ?? null;

        if ($contentType === null) {
            $this->strapi->log()->warning("Skipping homepage content type \"{$uid}\" because it is no longer registered.");

            return null;
        }

        return $contentType;
    }

    /**
     * @param list<string> $contentTypeUids
     * @return list<array<string, mixed>>
     */
    private function getConfiguration(array $contentTypeUids): array
    {
        /**
         * Don't use the strapi.store util because we need to make
         * more precise queries than exact key matches, in order to make as few queries as possible.
         */
        $coreStore = $this->strapi->db()->query('strapi::core-store');
        $rawConfigurations = $coreStore->findMany([
            'where' => [
                'key' => [
                    '$in' => array_map(
                        static fn (string $contentType): string => "plugin_content_manager_configuration_content_types::{$contentType}",
                        $contentTypeUids,
                    ),
                ],
            ],
        ]);

        return array_values(array_map(static function (array $rawConfiguration): array {
            $decoded = json_decode((string) ($rawConfiguration['value'] ?? ''), true);

            return is_array($decoded) ? $decoded : [];
        }, $rawConfigurations));
    }

    /** @return list<string> */
    private function getPermittedContentTypes(): array
    {
        $user = $this->strapi->requestContext()->get()?->state()->user();
        $readPermissions = $this->adminPermissionService()->findMany([
            'where' => [
                'role' => ['users' => ['id' => $user['id'] ?? null]],
                'action' => 'plugin::content-manager.explorer.read',
            ],
        ]);

        // Deduplicate subjects using a Set: the JOIN across permission -> role -> users produces one row
        // per role the user belongs to, so a multi-role user gets duplicate subjects.
        // Using a Set collapses them to unique content type UID.
        $subjects = [];
        foreach ($readPermissions as $permission) {
            $subject = $permission['subject'] ?? null;
            if (!is_string($subject) || $subject === '') {
                continue;
            }

            $contentType = $this->strapi->contentTypes()[$subject] ?? null;
            $contentTypeOptions = $contentType?->pluginOptions['content-manager'] ?? null;

            if (is_array($contentTypeOptions) && ($contentTypeOptions['visible'] ?? null) === false) {
                continue;
            }

            $subjects[$subject] = true;
        }

        return array_map('strval', array_keys($subjects));
    }

    private function getPermissionChecker(string $uid): PermissionChecker
    {
        /** @var PermissionChecker $service */
        $service = $this->strapi->plugin('content-manager')->service('permission-checker');

        return $service->create([
            'userAbility' => $this->strapi->requestContext()->get()?->state()->get('userAbility'),
            'model' => $uid,
        ]);
    }

    /**
     * @param list<string> $allowedContentTypeUids
     * @param list<array<string, mixed>> $configurations
     * @return list<ContentTypeMeta>
     */
    private function getContentTypesMeta(array $allowedContentTypeUids, array $configurations): array
    {
        $acc = [];
        foreach ($allowedContentTypeUids as $uid) {
            $configuration = null;
            foreach ($configurations as $config) {
                if (($config['uid'] ?? null) === $uid) {
                    $configuration = $config;
                    break;
                }
            }
            $contentType = $this->getRegisteredContentType($uid);

            if ($contentType === null) {
                continue;
            }

            $mainField = HomepageQueryUtils::resolveReadableMainField(
                $contentType,
                $configuration,
                $this->getPermissionChecker($uid),
            );
            $fields = HomepageQueryUtils::buildHomepageQueryFields($contentType, $mainField);
            $hasDraftAndPublish = ContentTypes::hasDraftAndPublish($contentType);

            $acc[] = [
                'fields' => $fields,
                'mainField' => $mainField,
                'contentType' => $contentType,
                'hasDraftAndPublish' => $hasDraftAndPublish,
                'uid' => $uid,
            ];
        }

        return $acc;
    }

    /**
     * Homepage widgets expect JSON-safe ISO strings. Returning `Date` objects can
     * serialize as `{}` after spread/clone (see https://github.com/strapi/strapi/issues/27013).
     */
    private static function toIsoDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable(is_scalar($value) ? (string) $value : '', new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array<array<string, mixed>> $documents
     * @param ContentTypeMeta $meta
     * @param list<string>|null $populate
     * @return list<array<string, mixed>>
     */
    private static function formatDocuments(array $documents, array $meta, ?array $populate = null): array
    {
        return array_values(array_map(static function (array $document) use ($meta, $populate): array {
            $additionalFields = [];
            foreach ($populate ?? [] as $key) {
                $additionalFields[$key] = $document[$key] ?? null;
            }

            return [
                'documentId' => $document['documentId'] ?? null,
                'locale' => $document['locale'] ?? null,
                'title' => $document[$meta['mainField']] ?? null,
                'contentTypeUid' => $meta['uid'],
                'contentTypeDisplayName' => $meta['contentType']->info['displayName'] ?? null,
                'kind' => $meta['contentType']->kind,
                ...$additionalFields,
                // Keep dates last so populate cannot overwrite with non-JSON-safe values
                'updatedAt' => self::toIsoDateString($document['updatedAt'] ?? null) ?? '',
                'publishedAt' => $meta['hasDraftAndPublish'] && !empty($document['publishedAt'])
                    ? self::toIsoDateString($document['publishedAt'])
                    : null,
            ];
        }, $documents));
    }

    /**
     * @param ContentTypeMeta $meta
     * @param array<string, mixed> $additionalQueryParams
     * @return array{permissionQuery: array<string, mixed>, titleField: string}
     */
    private function sanitizeHomepageQuery(array $meta, array $additionalQueryParams = []): array
    {
        $permissionQuery = $this->getPermissionChecker($meta['uid'])->sanitizedQuery([
            'limit' => self::MAX_DOCUMENTS,
            'fields' => $meta['fields'],
            ...$additionalQueryParams,
            'locale' => '*',
        ], 'read');

        $sanitizedFields = HomepageQueryUtils::compactSanitizedFields($permissionQuery['fields'] ?? null);
        if ($sanitizedFields !== null) {
            $permissionQuery['fields'] = $sanitizedFields;
        }

        return [
            'permissionQuery' => $permissionQuery,
            'titleField' => HomepageQueryUtils::resolveTitleField($meta['mainField'], $sanitizedFields),
        ];
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @return list<array<string, mixed>>
     */
    public function addStatusToDocuments(array $documents): array
    {
        $metadataService = $this->metadataService();

        return array_values(array_map(function (array $recentDocument) use ($metadataService): array {
            $hasDraftAndPublish = ContentTypes::hasDraftAndPublish(
                $this->strapi->contentType((string) $recentDocument['contentTypeUid']),
            );
            /**
             * Tries to query the other version of the document if draft and publish is enabled,
             * so that we know when to give the "modified" status.
             */
            ['availableStatus' => $availableStatus] = $metadataService->getMetadata(
                (string) $recentDocument['contentTypeUid'],
                $recentDocument,
                [
                    'availableStatus' => $hasDraftAndPublish,
                    'availableLocales' => false,
                ],
            );
            $status = $metadataService->getStatus($recentDocument, $availableStatus);

            $result = $recentDocument;
            if ($hasDraftAndPublish) {
                $result['status'] = $status;
            } else {
                unset($result['status']);
            }

            return $result;
        }, $documents));
    }

    /**
     * @param array<string, mixed> $additionalQueryParams
     * @return list<array<string, mixed>>
     */
    public function queryLastDocuments(array $additionalQueryParams = [], bool $draftAndPublishOnly = false): array
    {
        $permittedContentTypes = $this->getPermittedContentTypes();
        $allowedContentTypeUids = $draftAndPublishOnly
            ? array_values(array_filter($permittedContentTypes, function (string $uid): bool {
                $contentType = $this->getRegisteredContentType($uid);

                return $contentType !== null && ContentTypes::hasDraftAndPublish($contentType);
            }))
            : $permittedContentTypes;
        // Fetch the configuration for each content type in a single query
        $configurations = $this->getConfiguration($allowedContentTypeUids);
        // Get the necessary metadata for the documents
        $contentTypesMeta = $this->getContentTypesMeta($allowedContentTypeUids, $configurations);

        $recentDocuments = [];
        foreach ($contentTypesMeta as $meta) {
            ['permissionQuery' => $permissionQuery, 'titleField' => $titleField] = $this->sanitizeHomepageQuery($meta, $additionalQueryParams);

            $docs = $this->strapi->documents($meta['uid'])->findMany($permissionQuery);
            $populate = is_array($additionalQueryParams['populate'] ?? null) ? array_values($additionalQueryParams['populate']) : null;

            foreach (self::formatDocuments($docs, [...$meta, 'mainField' => $titleField], $populate) as $document) {
                $recentDocuments[] = $document;
            }
        }

        $sort = $additionalQueryParams['sort'] ?? null;
        $compareIso = static function (string $left, string $right, int $direction): int {
            // ISO-8601 strings compare lexicographically in chronological order
            if ($left < $right) {
                return -1 * $direction;
            }
            if ($left > $right) {
                return 1 * $direction;
            }

            return 0;
        };

        usort($recentDocuments, static function (array $a, array $b) use ($sort, $compareIso): int {
            switch ($sort) {
                case 'publishedAt:desc':
                    if (empty($a['publishedAt']) || empty($b['publishedAt'])) {
                        return 0;
                    }

                    return $compareIso($a['publishedAt'], $b['publishedAt'], -1);
                case 'publishedAt:asc':
                    if (empty($a['publishedAt']) || empty($b['publishedAt'])) {
                        return 0;
                    }

                    return $compareIso($a['publishedAt'], $b['publishedAt'], 1);
                case 'updatedAt:desc':
                    if (empty($a['updatedAt']) || empty($b['updatedAt'])) {
                        return 0;
                    }

                    return $compareIso($a['updatedAt'], $b['updatedAt'], -1);
                case 'updatedAt:asc':
                    if (empty($a['updatedAt']) || empty($b['updatedAt'])) {
                        return 0;
                    }

                    return $compareIso($a['updatedAt'], $b['updatedAt'], 1);
                default:
                    return 0;
            }
        });

        return array_slice($recentDocuments, 0, self::MAX_DOCUMENTS);
    }

    /** @return list<array<string, mixed>> */
    public function getRecentlyPublishedDocuments(): array
    {
        $recentlyPublishedDocuments = $this->queryLastDocuments(
            [
                'sort' => 'publishedAt:desc',
                'status' => 'published',
            ],
            true,
        );

        return $this->addStatusToDocuments($recentlyPublishedDocuments);
    }

    /** @return list<array<string, mixed>> */
    public function getRecentlyUpdatedDocuments(): array
    {
        $recentlyUpdatedDocuments = $this->queryLastDocuments([
            'sort' => 'updatedAt:desc',
        ]);

        return $this->addStatusToDocuments($recentlyUpdatedDocuments);
    }

    /** @return array{draft: int, published: int, modified: int} */
    public function getCountDocuments(): array
    {
        $permittedContentTypes = $this->getPermittedContentTypes();
        // Fetch the configuration for each content type in a single query
        $configurations = $this->getConfiguration($permittedContentTypes);
        // Get the necessary metadata for the documents
        $contentTypesMeta = $this->getContentTypesMeta($permittedContentTypes, $configurations);

        $countDocuments = [
            'draft' => 0,
            'published' => 0,
            'modified' => 0,
        ];

        $connection = $this->strapi->db()->getConnection();

        foreach ($contentTypesMeta as $meta) {
            $tableName = $this->strapi->contentType($meta['uid'])->collectionName;
            if ($tableName === '') {
                continue;
            }
            $table = $connection->quoteIdentifier($tableName);

            if (!$meta['hasDraftAndPublish']) {
                $publishedDocuments = $connection->fetchAssociative("SELECT COUNT(DISTINCT document_id) AS count FROM {$table}");
                $countDocuments['published'] += (int) ($publishedDocuments['count'] ?? 0);
                continue;
            }

            // Classify each document_id into a single bucket (draft / published / modified)
            // in one pass. Replaces three separate self-join queries that scaled poorly on
            // large tables — see https://github.com/strapi/strapi/issues/25200.
            $classified = "SELECT document_id, CASE
                  WHEN MAX(CASE WHEN published_at IS NOT NULL THEN 1 ELSE 0 END) = 0
                    THEN 'draft'
                  WHEN MAX(CASE WHEN published_at IS NULL THEN updated_at END) =
                       MAX(CASE WHEN published_at IS NOT NULL THEN updated_at END)
                    THEN 'published'
                  ELSE 'modified'
                END AS bucket FROM {$table} GROUP BY document_id";

            $counts = $connection->fetchAssociative(
                "SELECT COUNT(CASE WHEN bucket = 'draft' THEN 1 END) AS draft,
                 COUNT(CASE WHEN bucket = 'published' THEN 1 END) AS published,
                 COUNT(CASE WHEN bucket = 'modified' THEN 1 END) AS modified FROM ({$classified}) classified",
            );

            $countDocuments['draft'] += (int) ($counts['draft'] ?? 0);
            $countDocuments['published'] += (int) ($counts['published'] ?? 0);
            $countDocuments['modified'] += (int) ($counts['modified'] ?? 0);
        }

        return $countDocuments;
    }
}
