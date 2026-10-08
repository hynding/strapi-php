<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/services/document-metadata.ts.
 *
 * A document version is an array (`id`, `documentId`, `locale`, `updatedAt`, `publishedAt`,
 * `localizations`...). The i18n plugin is not ported: when it is not installed, the default locale
 * and the non-localized attributes come from core's localization service.
 *
 * @phpstan-type DocumentVersion array<string, mixed>
 * @phpstan-type GetMetadataOptions array{availableLocales?: bool, availableStatus?: bool}
 */
final class DocumentMetadata
{
    /** Scalar fields that can be used in a DB `select` */
    private const array AVAILABLE_STATUS_SCALAR_FIELDS = [
        'id',
        'documentId',
        'locale',
        'updatedAt',
        'createdAt',
        'publishedAt',
    ];

    /** Relation populate shared by both the fast path and the full path */
    private const array AVAILABLE_STATUS_POPULATE = [
        'createdBy' => ['select' => ['id', 'firstname', 'lastname', 'email']],
        'updatedBy' => ['select' => ['id', 'firstname', 'lastname', 'email']],
    ];

    /** All fields to pick from a hydrated result (scalars + populated relations + virtual) */
    private const array AVAILABLE_STATUS_FIELDS = [
        ...self::AVAILABLE_STATUS_SCALAR_FIELDS,
        'createdBy',
        'updatedBy',
        'status',
    ];

    private const array AVAILABLE_LOCALES_FIELDS = [
        'id',
        'documentId',
        'locale',
        'updatedAt',
        'createdAt',
        'publishedAt',
    ];

    public const array CONTENT_MANAGER_STATUS = [
        'PUBLISHED' => 'published',
        'DRAFT' => 'draft',
        'MODIFIED' => 'modified',
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Returns a DB filter that matches the opposite publish status.
     *
     * @return array<string, true>
     */
    private static function oppositePublishStatus(mixed $publishedAt): array
    {
        return $publishedAt !== null ? ['$null' => true] : ['$notNull' => true];
    }

    /** `new Date(value).getTime()` (milliseconds); 0 when absent or invalid. */
    public static function toTime(mixed $value): float
    {
        if ($value instanceof \DateTimeInterface) {
            return (float) $value->format('Uv');
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value) || $value === '') {
            return 0.0;
        }

        try {
            return (float) (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Uv');
        } catch (\Exception) {
            return 0.0;
        }
    }

    /**
     * Checks if the provided document version has been modified after all other versions.
     *
     * @param DocumentVersion|null $version
     * @param DocumentVersion|null $otherVersion
     */
    private static function getIsVersionLatestModification(?array $version, ?array $otherVersion): bool
    {
        if ($version === null || empty($version['updatedAt'])) {
            return false;
        }

        $versionUpdatedAt = self::toTime($version['updatedAt']);

        $otherUpdatedAt = !empty($otherVersion['updatedAt']) ? self::toTime($otherVersion['updatedAt']) : 0.0;

        return $versionUpdatedAt > $otherUpdatedAt;
    }

    /**
     * Returns available locales of a document for the current status.
     *
     * The result is sorted with the default locale (as defined by the i18n plugin)
     * at index 0 when present. This is the canonical-source invariant relied on by
     * `useDocument.getInitialFormValues` in the admin: when creating a new locale
     * draft, non-localized scalar/media values are inherited from
     * `availableLocales[0]`. Putting the default locale first means inheritance
     * stays predictable when sibling locales have drifted on non-localized fields
     * (which can happen because the server only syncs non-localized fields at
     * locale-creation time, not on subsequent updates — see
     * `copyNonLocalizedFields` in document-service/internationalization.ts).
     *
     * @param DocumentVersion $version
     * @param array<DocumentVersion> $allVersions
     * @return list<DocumentVersion>
     */
    public function getAvailableLocales(string $uid, array $version, array $allVersions): array
    {
        // Group all versions by locale
        $versionsByLocale = [];
        foreach ($allVersions as $v) {
            $versionsByLocale[\Strapi\Utils\Primitives\Strings::stringify($v['locale'] ?? null)][] = $v;
        }

        // Delete the current locale
        if (!empty($version['locale'])) {
            unset($versionsByLocale[(string) $version['locale']]);
        }

        // For each locale, get the ones with the same status
        // There will not be a draft and a version counterpart if the content
        // type does not have draft and publish
        $model = $this->strapi->getModel($uid);

        $filtered = [];
        foreach ($versionsByLocale as $localeVersions) {
            if (!ContentTypes::hasDraftAndPublish($model)) {
                $filtered[] = $localeVersions[0];
                continue;
            }

            $draftVersion = null;
            foreach ($localeVersions as $v) {
                if (($v['publishedAt'] ?? null) === null) {
                    $draftVersion = $v;
                    break;
                }
            }

            if ($draftVersion === null) {
                continue;
            }

            $otherVersions = array_values(array_filter(
                $localeVersions,
                static fn (array $v): bool => ($v['id'] ?? null) !== ($draftVersion['id'] ?? null),
            ));

            $filtered[] = [
                ...$draftVersion,
                'status' => $this->getStatus($draftVersion, $otherVersions),
            ];
        }

        // Sort the default locale first so `availableLocales[0]` is the canonical
        // source for non-localized field inheritance in the admin. Guarded so that
        // we no-op if the i18n plugin or its locales service is unavailable.
        $defaultLocaleCode = null;
        try {
            if ($this->strapi->hasPlugin('i18n')) {
                $locales = $this->strapi->plugin('i18n')->service('locales');
                $defaultLocaleCode = method_exists($locales, 'getDefaultLocale') ? $locales->getDefaultLocale() : null;
            } else {
                $defaultLocaleCode = $this->strapi->localization()->getDefaultLocale();
            }
        } catch (\Throwable) {
            // i18n plugin disabled or service errored — leave order untouched.
        }

        if (!is_string($defaultLocaleCode) || $defaultLocaleCode === '') {
            return $filtered;
        }

        // Stable partition: move the default locale entry to the front, keep the
        // rest of the order intact.
        $defaultEntries = array_values(array_filter($filtered, static fn (array $entry): bool => ($entry['locale'] ?? null) === $defaultLocaleCode));
        $otherEntries = array_values(array_filter($filtered, static fn (array $entry): bool => ($entry['locale'] ?? null) !== $defaultLocaleCode));

        return [...$defaultEntries, ...$otherEntries];
    }

    /**
     * Returns available status of a document for the current locale
     *
     * @param DocumentVersion $version
     * @param array<DocumentVersion> $allVersions
     * @return array<mixed>|null
     */
    public function getAvailableStatus(array $version, array $allVersions): ?array
    {
        // Find the other status of the document
        $status = ($version['publishedAt'] ?? null) !== null
            ? self::CONTENT_MANAGER_STATUS['DRAFT']
            : self::CONTENT_MANAGER_STATUS['PUBLISHED'];

        // Get version that match the current locale and not match the current status
        $availableStatus = null;
        foreach ($allVersions as $v) {
            $matchLocale = ($v['locale'] ?? null) === ($version['locale'] ?? null);
            $matchStatus = $status === 'published' ? ($v['publishedAt'] ?? null) !== null : ($v['publishedAt'] ?? null) === null;
            if ($matchLocale && $matchStatus) {
                $availableStatus = $v;
                break;
            }
        }

        if ($availableStatus === null) {
            return null;
        }

        // Pick status fields (at fields, status, by fields), use lodash fp
        return Objects::pick($availableStatus, self::AVAILABLE_STATUS_FIELDS);
    }

    /**
     * Get the available status of many documents, useful for batch operations
     *
     * @param list<DocumentVersion> $documents
     * @return list<array<string, mixed>>
     */
    public function getManyAvailableStatus(string $uid, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        // The status and locale of all documents should be the same
        $status = ($documents[0]['publishedAt'] ?? null) !== null ? 'published' : 'draft';
        $locales = array_values(array_filter(array_map(static fn (array $d): mixed => $d['locale'] ?? null, $documents)));

        $where = [
            'documentId' => ['$in' => array_values(array_filter(array_map(static fn (array $d): mixed => $d['documentId'] ?? null, $documents)))],
            'publishedAt' => ['$null' => $status === 'published'],
        ];

        // If there is any locale to filter (if i18n is enabled)
        if (count($locales) === count($documents)) {
            $where['locale'] = ['$in' => $locales];
        } elseif ($locales !== []) {
            /*
             * The batch mixes localized and non-localized versions, which happens when a
             * content type holds rows with a locale while others have none — for instance
             * after i18n is disabled on it, or when a locale is sent to a non-localized
             * content type through the API.
             *
             * `locales` only holds the locales that are actually set, so filtering on it
             * alone would drop the counterparts of every version whose locale is null, and
             * those versions would be reported as drafts even though they are published.
             */
            $where['$or'] = [['locale' => ['$in' => $locales]], ['locale' => ['$null' => true]]];
        }

        return array_values($this->strapi->db()->query($uid)->findMany([
            'where' => $where,
            'select' => self::AVAILABLE_STATUS_SCALAR_FIELDS,
        ]));
    }

    /**
     * @param DocumentVersion $version
     * @param list<DocumentVersion>|null $otherDocumentStatuses
     */
    public function getStatus(array $version, ?array $otherDocumentStatuses = null): string
    {
        $draftVersion = null;
        $publishedVersion = null;

        if (!empty($version['publishedAt'])) {
            $publishedVersion = $version;
        } else {
            $draftVersion = $version;
        }

        $otherVersion = $otherDocumentStatuses !== null && $otherDocumentStatuses !== [] ? array_values($otherDocumentStatuses)[0] : null;
        if (!empty($otherVersion['publishedAt'])) {
            $publishedVersion = $otherVersion;
        } elseif ($otherVersion !== null) {
            $draftVersion = $otherVersion;
        }

        if ($draftVersion === null) {
            return self::CONTENT_MANAGER_STATUS['PUBLISHED'];
        }
        if ($publishedVersion === null) {
            return self::CONTENT_MANAGER_STATUS['DRAFT'];
        }

        /*
         * The document is modified if the draft version has been updated more
         * recently than the published version.
         */
        $isDraftModified = self::getIsVersionLatestModification($draftVersion, $publishedVersion);

        return $isDraftModified ? self::CONTENT_MANAGER_STATUS['MODIFIED'] : self::CONTENT_MANAGER_STATUS['PUBLISHED'];
    }

    /**
     * TODO is it necessary to return metadata on every page of the CM
     * We could refactor this so the locales are only loaded when they're
     * needed. e.g. in the bulk locale action modal.
     *
     * @param DocumentVersion $version
     * @param GetMetadataOptions $options
     * @return array{availableLocales: list<array<mixed>>, availableStatus: list<array<mixed>>, versions: array<array<mixed>>}
     */
    public function getMetadata(string $uid, array $version, array $options = []): array
    {
        $availableLocales = $options['availableLocales'] ?? true;
        $availableStatus = $options['availableStatus'] ?? true;

        $model = $this->strapi->getModel($uid);
        $hasDnP = ContentTypes::hasDraftAndPublish($model);
        $isLocalized = ($model?->pluginOptions['i18n']['localized'] ?? null) === true;

        if (!$availableLocales && !$availableStatus) {
            // Nothing to compute.
            return ['availableLocales' => [], 'availableStatus' => [], 'versions' => []];
        }
        if (!$isLocalized && !$hasDnP) {
            // If there are no locales and no draft/publish, there's only ever 1 version of any document.
            return ['availableLocales' => [], 'availableStatus' => [], 'versions' => []];
        }

        $onlyStatusIsRelevant = $hasDnP && (!$isLocalized || !$availableLocales);
        if ($onlyStatusIsRelevant) {
            $otherVersion = $availableStatus
                ? $this->strapi->db()->query($uid)->findOne([
                    'where' => [
                        'documentId' => $version['documentId'] ?? null,
                        ...(!empty($version['locale']) ? ['locale' => $version['locale']] : []),
                        'publishedAt' => self::oppositePublishStatus($version['publishedAt'] ?? null),
                    ],
                    'select' => self::AVAILABLE_STATUS_SCALAR_FIELDS,
                    'populate' => self::AVAILABLE_STATUS_POPULATE,
                ])
                : null;

            return [
                'availableLocales' => [],
                'availableStatus' => $otherVersion !== null ? [Objects::pick($otherVersion, self::AVAILABLE_STATUS_FIELDS)] : [],
                'versions' => [],
            ];
        }

        // Full path for localized content types
        // TODO: Ignore publishedAt if availableStatus=false, and ignore locale if
        // i18n is disabled

        // Include non-translatable scalar and media fields in availableLocales for i18n prefilling
        $nonLocalizedFields = [];
        $nonLocalizedMediaFields = [];
        try {
            $allNonLocalized = null;
            if ($this->strapi->hasPlugin('i18n')) {
                $i18nService = $this->strapi->plugin('i18n')->service('content-types');
                if (method_exists($i18nService, 'getNonLocalizedAttributes') && $model !== null) {
                    $allNonLocalized = $i18nService->getNonLocalizedAttributes($model);
                }
            } elseif ($model !== null) {
                $allNonLocalized = $this->strapi->localization()->getNonLocalizedAttributes($model);
            }

            if (is_array($allNonLocalized) && $model !== null) {
                // Get scalar and media attributes separately
                $scalarAttrs = ContentTypes::getScalarAttributes($model);
                $mediaAttrs = ContentTypes::getMediaAttributes($model);

                // Separate scalar fields (can be in fields array) from media fields (need to be populated)
                $nonLocalizedFields = array_values(array_filter(
                    $allNonLocalized,
                    static fn (mixed $field): bool => is_string($field) && isset($model->attributes[$field]) && in_array($field, $scalarAttrs, true),
                ));
                $nonLocalizedMediaFields = array_values(array_filter(
                    $allNonLocalized,
                    static fn (mixed $field): bool => is_string($field) && isset($model->attributes[$field]) && in_array($field, $mediaAttrs, true),
                ));
            }
        } catch (\Throwable) {
            // i18n plugin might not be enabled or might error, ignore silently
        }

        // Build populate object for non-localized media fields
        $mediaPopulate = [];
        foreach ($nonLocalizedMediaFields as $field) {
            $mediaPopulate[$field] = [
                'populate' => [
                    'folder' => true,
                ],
            ];
        }

        $params = [
            'populate' => [
                ...$mediaPopulate,
                ...self::AVAILABLE_STATUS_POPULATE,
            ],
            'fields' => array_values(array_unique([...self::AVAILABLE_LOCALES_FIELDS, ...$nonLocalizedFields])),
            'filters' => [
                'documentId' => $version['documentId'] ?? null,
            ],
        ];

        $dbParams = $this->strapi->get('query-params')->transform($uid, $params);
        $versions = array_values($this->strapi->db()->query($uid)->findMany($dbParams));

        // TODO: Remove use of available locales and use localizations instead
        $availableLocalesResult = $availableLocales
            ? $this->getAvailableLocales($uid, $version, $versions)
            : [];

        $availableStatusResult = $availableStatus
            ? $this->getAvailableStatus($version, $versions)
            : null;

        return [
            'availableLocales' => $availableLocalesResult,
            'availableStatus' => $availableStatusResult !== null ? [$availableStatusResult] : [],
            'versions' => $versions,
        ];
    }

    /**
     * Returns associated metadata of a document:
     * - Available locales of the document for the current status
     * - Available status of the document for the current locale
     *
     * @param DocumentVersion|null $document
     * @param GetMetadataOptions $opts
     * @return array{data: mixed, meta: array{availableLocales: list<mixed>, availableStatus: list<mixed>}}
     */
    public function formatDocumentWithMetadata(string $uid, ?array $document, array $opts = []): array
    {
        if ($document === null) {
            return [
                'data' => $document,
                'meta' => [
                    'availableLocales' => [],
                    'availableStatus' => [],
                ],
            ];
        }

        $hasDraftAndPublish = ContentTypes::hasDraftAndPublish($this->strapi->getModel($uid));

        // Ignore available status if the content type does not have draft and publish
        if (!$hasDraftAndPublish) {
            $opts['availableStatus'] = false;
        }

        $metadata = $this->getMetadata($uid, $document, $opts);
        $versions = $metadata['versions'];
        $meta = [
            'availableLocales' => $metadata['availableLocales'],
            'availableStatus' => $metadata['availableStatus'],
        ];

        // Populate localization statuses
        if (is_array($document['localizations'] ?? null) && $document['localizations'] !== []) {
            $document['localizations'] = array_map(function (mixed $d) use ($versions): mixed {
                if (!is_array($d)) {
                    return $d;
                }
                // Find the counterpart version (same documentId + locale, opposite publishedAt) from
                // the already-fetched versions array, avoiding an extra DB query.
                $counterpart = null;
                foreach ($versions as $v) {
                    if (($v['documentId'] ?? null) === ($d['documentId'] ?? null)
                        && ($v['locale'] ?? null) === ($d['locale'] ?? null)
                        && ((($d['publishedAt'] ?? null) === null) !== (($v['publishedAt'] ?? null) === null))) {
                        $counterpart = $v;
                        break;
                    }
                }

                return [
                    ...$d,
                    'status' => $this->getStatus($d, $counterpart !== null ? [$counterpart] : []),
                ];
            }, $document['localizations']);
        }

        $data = $document;
        // Add status to the document only if draft and publish is enabled
        if ($hasDraftAndPublish) {
            $data['status'] = $this->getStatus($document, $meta['availableStatus']);
        } else {
            unset($data['status']);
        }

        return [
            'data' => $data,
            'meta' => $meta,
        ];
    }
}
