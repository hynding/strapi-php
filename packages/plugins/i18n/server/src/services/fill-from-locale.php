<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\ContentManager\Services\ContentTypes as ContentManagerContentTypes;
use Strapi\ContentManager\Services\DocumentMetadata;
use Strapi\ContentManager\Services\PopulateBuilder;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes as ContentTypeUtils;

/**
 * Port of server/src/services/fill-from-locale.ts (`createFillFromLocaleService`).
 *
 * @phpstan-type ValidRelation array{documentId: string, id?: mixed, locale?: string}
 * @phpstan-type ResolvedRelation array{documentId: string, id: int|string, locale?: string}
 * @phpstan-type UidResolutionData array{blocked: bool, mainField: string, bySourceDocumentId: array<string, ResolvedRelation|null>, statusById: array<array-key, string|null>, labelById: array<array-key, mixed>}
 */
final class FillFromLocale
{
    private const string READ_ACTION = 'plugin::content-manager.explorer.read';

    private const string TEMP_KEY_DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const array FIELDS_TO_IGNORE = [
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'id',
        'documentId',
        'publishedAt',
        'strapi_stage',
        'strapi_assignee',
        'locale',
        'status',
        'localizations',
    ];

    private const array STATUS_FIELDS = ['id', 'documentId', 'locale', 'updatedAt', 'publishedAt'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Returns the main display field for a model (e.g. title, name).
     * Uses content-manager configuration when available, falls back to first string attribute or 'id'.
     */
    private function getMainField(string $targetUid): string
    {
        $contentManagerContentTypeService = $this->strapi->plugin('content-manager')->service('content-types');
        assert($contentManagerContentTypeService instanceof ContentManagerContentTypes);
        $configuration = $contentManagerContentTypeService->findConfiguration(['uid' => $targetUid]);

        return (string) ($configuration['settings']['mainField'] ?? 'id');
    }

    /**
     * Returns the display label for a relation in form state.
     * Empty configured values stay untranslated so the admin can provide its localized fallback.
     *
     * @param array<string, mixed> $relation
     */
    private static function getRelationLabel(array $relation, string $mainField): string
    {
        $label = $relation[$mainField] ?? null;
        if ($label === null || is_string($label)) {
            return $label ?? '';
        }

        return (string) ($relation['documentId'] ?? '');
    }

    /**
     * Mirrors fractional-indexing's initial key range, i.e. generateNKeysBetween(undefined, undefined, n).
     *
     * @return list<string>
     */
    public static function generateInitialTempKeys(int $length): array
    {
        $previousKey = null;
        $keys = [];

        for ($i = 0; $i < $length; ++$i) {
            $nextKey = self::incrementInitialTempKey($previousKey);
            $previousKey = $nextKey;
            $keys[] = $nextKey;
        }

        return $keys;
    }

    private static function incrementInitialTempKey(?string $key = null): string
    {
        if ($key === null || $key === '') {
            return 'a' . substr(self::TEMP_KEY_DIGITS, 0, 1);
        }

        $head = $key[0];
        $digits = str_split(substr($key, 1));
        if ($digits === ['']) {
            $digits = [];
        }
        $carry = true;

        for ($index = count($digits) - 1; $carry && $index >= 0; --$index) {
            $position = strpos(self::TEMP_KEY_DIGITS, $digits[$index]);
            $nextDigitIndex = $position === false ? 0 : $position + 1;

            if ($nextDigitIndex === 0) {
                throw new \RuntimeException("Invalid temporary key digit: {$digits[$index]}");
            }

            if ($nextDigitIndex === strlen(self::TEMP_KEY_DIGITS)) {
                $digits[$index] = substr(self::TEMP_KEY_DIGITS, 0, 1);
            } else {
                $digits[$index] = substr(self::TEMP_KEY_DIGITS, $nextDigitIndex, 1);
                $carry = false;
            }
        }

        if (!$carry) {
            return $head . implode('', $digits);
        }

        if ($head === 'z') {
            throw new \RuntimeException('Cannot increment temporary keys any further');
        }

        $nextHead = chr(ord($head) + 1);
        if ($nextHead > 'a') {
            $digits[] = substr(self::TEMP_KEY_DIGITS, 0, 1);
        } else {
            array_pop($digits);
        }

        return $nextHead . implode('', $digits);
    }

    /**
     * Normalizes a value to an array: arrays pass through, single values become [value], null/undefined become [].
     *
     * @return list<mixed>
     */
    private static function normalizeToArray(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        if ($value !== null && $value !== false && $value !== '' && $value !== 0) {
            return [$value];
        }

        return [];
    }

    /** Returns true if the value is a valid relation object with documentId. */
    private static function isValidRelation(mixed $rel): bool
    {
        return is_array($rel) && array_key_exists('documentId', $rel) && !empty($rel['documentId']);
    }

    /**
     * @param list<ValidRelation> $relations
     * @return list<ValidRelation>
     */
    private static function filterValid(array $relations): array
    {
        /** @var list<ValidRelation> $valid */
        $valid = array_values(array_filter($relations, self::isValidRelation(...)));

        return $valid;
    }

    private function isLocalized(string $uid): bool
    {
        if (!$this->strapi->hasPlugin('i18n')) {
            return false;
        }
        $contentTypes = $this->strapi->plugin('i18n')->service('content-types');
        assert($contentTypes instanceof ContentTypes);

        return $contentTypes->isLocalizedContentType($this->strapi->getModel($uid));
    }

    /**
     * Maps relations from the source locale to target locale (batched for better performance).
     *
     * @param list<ValidRelation> $relations
     * @return list<ResolvedRelation|null>
     */
    private function resolveRelationsForLocaleBatch(array $relations, string $targetUid, string $targetLocale): array
    {
        $isTargetLocalized = $this->isLocalized($targetUid);

        if (!$isTargetLocalized) {
            return array_map(static function (array $rel): ?array {
                if (!array_key_exists('id', $rel) || $rel['id'] === null) {
                    return null;
                }

                return ['documentId' => $rel['documentId'], 'id' => $rel['id']];
            }, $relations);
        }

        $documentIds = array_values(array_unique(array_map(static fn (array $r): string => $r['documentId'], $relations)));
        $targetEntries = $this->strapi->db()->query($targetUid)->findMany([
            'where' => ['documentId' => ['$in' => $documentIds], 'locale' => $targetLocale],
            'select' => self::STATUS_FIELDS,
        ]);
        $byDocumentId = [];
        foreach ($targetEntries as $e) {
            $existing = $byDocumentId[$e['documentId']] ?? null;
            if ($existing === null || (($e['publishedAt'] ?? null) === null && ($existing['publishedAt'] ?? null) !== null)) {
                $byDocumentId[$e['documentId']] = $e;
            }
        }

        return array_map(static function (array $rel) use ($byDocumentId): ?array {
            $entry = $byDocumentId[$rel['documentId']] ?? null;
            if ($entry === null) {
                return null;
            }

            return [
                'documentId' => $entry['documentId'],
                'id' => $entry['id'],
                'locale' => $entry['locale'],
            ];
        }, $relations);
    }

    /**
     * Builds the where clause for relation queries: documentIds + locales (when target model is localized).
     *
     * @param list<ResolvedRelation> $relations
     * @param list<string> $documentIds
     * @return array<string, mixed>
     */
    private function buildWhereForRelations(array $relations, array $documentIds, string $targetUid): array
    {
        $where = [
            'documentId' => ['$in' => $documentIds],
        ];
        if ($this->isLocalized($targetUid)) {
            $locales = array_values(array_unique(array_filter(array_map(static fn (array $r): mixed => $r['locale'] ?? null, $relations))));
            if (count($locales) > 0) {
                $where['locale'] = ['$in' => $locales];
            }
        }

        return $where;
    }

    /** @return Schema|array{attributes: array<string, mixed>} */
    private function componentSchema(string $uid): Schema|array
    {
        return $this->strapi->getModel($uid) ?? ['attributes' => []];
    }

    /**
     * Walk the full document tree and collect all valid relation objects grouped by targetUid.
     *
     * @param array<string, mixed> $data
     * @param Schema|array<string, mixed> $schema
     * @return array<string, list<ValidRelation>>
     */
    private function collectRelationsByUid(array $data, Schema|array $schema): array
    {
        $result = [];

        $collect = function (mixed $d, Schema|array $s) use (&$collect, &$result): void {
            if (!is_array($d)) {
                return;
            }
            $attributes = ContentTypeUtils::attributes($s);
            foreach ($d as $key => $value) {
                $attribute = $attributes[(string) $key] ?? null;
                if ($attribute === null) {
                    continue;
                }

                $type = $attribute['type'] ?? null;
                if ($type === 'relation') {
                    $relation = (string) ($attribute['relation'] ?? '');
                    $target = $attribute['target'] ?? null;
                    if (str_contains(strtolower($relation), 'morph') || !is_string($target) || $target === '') {
                        continue;
                    }
                    /** @var list<ValidRelation> $validRels */
                    $validRels = array_values(array_filter(self::normalizeToArray($value), self::isValidRelation(...)));
                    if (count($validRels) > 0) {
                        $result[$target] = [...($result[$target] ?? []), ...$validRels];
                    }
                } elseif ($type === 'component') {
                    $compSchema = $this->componentSchema((string) ($attribute['component'] ?? ''));
                    if (!empty($attribute['repeatable']) && is_array($value) && array_is_list($value)) {
                        foreach ($value as $item) {
                            $collect($item, $compSchema);
                        }
                    } elseif (!empty($value)) {
                        $collect($value, $compSchema);
                    }
                } elseif ($type === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                    foreach ($value as $item) {
                        $compUid = is_array($item) ? (string) ($item['__component'] ?? '') : '';
                        $collect($item, $this->componentSchema($compUid));
                    }
                }
            }
        };

        $collect($data, $schema);

        return $result;
    }

    /**
     * Resolves all relation targets for the requested locale in batches (per related content-type UID):
     * locale mapping, permissions, status, and labels.
     *
     * @param array<string, list<ValidRelation>> $relationsByUid
     * @return array<string, UidResolutionData>
     */
    private function resolveAllRelationsBatched(array $relationsByUid, string $targetLocale, Ability $userAbility): array
    {
        $result = [];
        foreach ($relationsByUid as $targetUid => $allRels) {
            $targetUid = (string) $targetUid;
            $empty = [
                'blocked' => !$userAbility->can(self::READ_ACTION, $targetUid),
                'mainField' => '',
                'bySourceDocumentId' => [],
                'statusById' => [],
                'labelById' => [],
            ];

            if ($empty['blocked']) {
                $result[$targetUid] = $empty;
                continue;
            }

            $mainField = $this->getMainField($targetUid);
            $validRels = self::filterValid($allRels);

            if (count($validRels) === 0) {
                $result[$targetUid] = [...$empty, 'mainField' => $mainField];
                continue;
            }

            // Deduplicate by documentId before querying
            $uniqueByDocumentId = [];
            foreach ($validRels as $rel) {
                $uniqueByDocumentId[$rel['documentId']] = $rel;
            }
            $uniqueRels = array_values($uniqueByDocumentId);
            $resolvedList = $this->resolveRelationsForLocaleBatch($uniqueRels, $targetUid, $targetLocale);

            $bySourceDocumentId = [];
            foreach ($uniqueRels as $i => $rel) {
                $bySourceDocumentId[$rel['documentId']] = $resolvedList[$i];
            }

            /** @var list<ResolvedRelation> $resolvedEntries */
            $resolvedEntries = array_values(array_filter($resolvedList, static fn (?array $r): bool => $r !== null));

            if (count($resolvedEntries) === 0) {
                $result[$targetUid] = [
                    'blocked' => false,
                    'mainField' => $mainField,
                    'bySourceDocumentId' => $bySourceDocumentId,
                    'statusById' => [],
                    'labelById' => [],
                ];
                continue;
            }

            $documentIds = array_values(array_unique(array_map(static fn (array $r): string => $r['documentId'], $resolvedEntries)));
            $where = $this->buildWhereForRelations($resolvedEntries, $documentIds, $targetUid);

            $allStatusEntries = ContentTypeUtils::hasDraftAndPublish($this->strapi->getModel($targetUid))
                ? $this->strapi->db()->query($targetUid)->findMany(['where' => $where, 'select' => self::STATUS_FIELDS])
                : [];
            $labelEntries = $this->strapi->db()->query($targetUid)->findMany([
                'where' => $where,
                'select' => array_values(array_unique(['documentId', 'id', 'locale', $mainField])),
            ]);

            // Build status map
            $statusById = [];
            if (count($allStatusEntries) > 0 && $this->strapi->hasPlugin('content-manager')) {
                $documentMetadata = $this->strapi->plugin('content-manager')->service('document-metadata');
                assert($documentMetadata instanceof DocumentMetadata);
                $isLocalized = $this->isLocalized($targetUid);
                $groupKey = static fn (array $e): string => $isLocalized && !empty($e['locale'])
                    ? "{$e['documentId']}:{$e['locale']}"
                    : (string) $e['documentId'];

                $byGroup = [];
                foreach ($allStatusEntries as $e) {
                    $byGroup[$groupKey($e)][] = $e;
                }

                foreach ($resolvedEntries as $entry) {
                    $entries = $byGroup[$groupKey($entry)] ?? [];
                    if (count($entries) === 0) {
                        continue;
                    }
                    $version = $entries[0];
                    foreach ($entries as $e) {
                        if (($e['id'] ?? null) === $entry['id']) {
                            $version = $e;
                            break;
                        }
                    }
                    $otherVersions = array_values(array_filter($entries, static fn (array $e): bool => ($e['id'] ?? null) !== ($version['id'] ?? null)));
                    $statusById[$entry['id']] = $documentMetadata->getStatus($version, $otherVersions);
                }
            }

            // Build label map
            $labelById = [];
            foreach ($labelEntries as $entry) {
                $labelById[$entry['id']] = $entry[$mainField] ?? null;
            }

            $result[$targetUid] = [
                'blocked' => false,
                'mainField' => $mainField,
                'bySourceDocumentId' => $bySourceDocumentId,
                'statusById' => $statusById,
                'labelById' => $labelById,
            ];
        }

        return $result;
    }

    /**
     * Build the { connect, disconnect } for a single relation attribute using pre-fetched data.
     *
     * @param array<string, mixed> $attribute
     * @param array<string, UidResolutionData> $preResolved
     * @return array{connect: list<array<string, mixed>>, disconnect: list<mixed>}
     */
    private static function buildConnectFromPreResolved(mixed $value, array $attribute, array $preResolved): array
    {
        $relation = (string) ($attribute['relation'] ?? '');
        $target = $attribute['target'] ?? null;
        if (str_contains(strtolower($relation), 'morph') || !is_string($target) || $target === '') {
            return ['connect' => [], 'disconnect' => []];
        }

        $uidData = $preResolved[$target] ?? null;
        if ($uidData === null || $uidData['blocked']) {
            return ['connect' => [], 'disconnect' => []];
        }

        /** @var list<ValidRelation> $validRels */
        $validRels = array_values(array_filter(self::normalizeToArray($value), self::isValidRelation(...)));
        if (count($validRels) === 0) {
            return ['connect' => [], 'disconnect' => []];
        }

        $connect = [];
        foreach ($validRels as $index => $rel) {
            $resolved = $uidData['bySourceDocumentId'][$rel['documentId']] ?? null;
            if ($resolved === null) {
                continue;
            }

            $status = $uidData['statusById'][$resolved['id']] ?? null;
            $mainFieldValue = $uidData['labelById'][$resolved['id']] ?? null;
            $relationWithMainField = [
                ...$resolved,
                $uidData['mainField'] => $mainFieldValue,
            ];
            if ($status !== null) {
                $relationWithMainField['status'] = $status;
            }

            $connect[] = [
                ...$relationWithMainField,
                '__temp_key__' => "a{$index}",
                'label' => self::getRelationLabel($relationWithMainField, $uidData['mainField']),
            ];
        }

        return ['connect' => $connect, 'disconnect' => []];
    }

    /**
     * Recursively process document data: remove internal fields and resolve relations using
     * pre-fetched resolution data (no additional DB calls).
     *
     * @param Schema|array<string, mixed> $schema
     * @param array<string, UidResolutionData> $preResolved
     * @return array<string, mixed>
     */
    private function processDocumentData(mixed $data, Schema|array $schema, array $preResolved): array
    {
        if (!is_array($data) || $data === []) {
            return [];
        }

        $result = [];
        $attributes = ContentTypeUtils::attributes($schema);

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::FIELDS_TO_IGNORE, true)) {
                continue;
            }

            $attribute = $attributes[$key] ?? null;
            if ($attribute === null) {
                $result[$key] = $value;
                continue;
            }

            $type = $attribute['type'] ?? null;

            if ($type === 'password') {
                continue;
            }

            if ($type === 'relation') {
                $result[$key] = self::buildConnectFromPreResolved($value, $attribute, $preResolved);
                continue;
            }

            if ($type === 'component') {
                $compSchema = $this->componentSchema((string) ($attribute['component'] ?? ''));
                if (!empty($attribute['repeatable']) && is_array($value) && array_is_list($value)) {
                    $tempKeys = self::generateInitialTempKeys(count($value));
                    $result[$key] = array_map(
                        fn (mixed $item, int $index): array => [...$this->processDocumentData($item, $compSchema, $preResolved), '__temp_key__' => $tempKeys[$index]],
                        $value,
                        array_keys($value),
                    );
                } elseif (!empty($value)) {
                    $result[$key] = $this->processDocumentData($value, $compSchema, $preResolved);
                } else {
                    $result[$key] = $value;
                }
                continue;
            }

            if ($type === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                $tempKeys = self::generateInitialTempKeys(count($value));
                $result[$key] = array_map(function (mixed $item, int $index) use ($tempKeys, $preResolved): array {
                    $compSchema = $this->componentSchema(is_array($item) ? (string) ($item['__component'] ?? '') : '');

                    return [...$this->processDocumentData($item, $compSchema, $preResolved), '__temp_key__' => $tempKeys[$index]];
                }, $value, array_keys($value));
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Fetch the raw populated document for the given locale without any transformation.
     * The caller is responsible for sanitizing the output before passing it to transformDocument.
     *
     * @return array<string, mixed>|null
     */
    public function fetchRawDocument(string $model, string $sourceLocale, ?string $documentId = null): ?array
    {
        $populateBuilderService = $this->strapi->plugin('content-manager')->service('populate-builder');
        assert($populateBuilderService instanceof PopulateBuilder);
        $modelDef = $this->strapi->getModel($model);

        if ($modelDef === null) {
            throw new \RuntimeException("Model {$model} not found");
        }

        // Build populate WITHOUT countRelations so we get full relation objects
        $populate = $populateBuilderService($model)->populateDeep(INF)->build();

        $docs = $this->strapi->documents($model);
        $baseParams = [
            'locale' => $sourceLocale,
            'populate' => $populate,
        ];

        return $documentId !== null && $documentId !== ''
            ? $docs->findOne([...$baseParams, 'documentId' => $documentId])
            : $docs->findFirst($baseParams);
    }

    /**
     * Transform a (sanitized) document: strip internal fields, resolve relations to the target
     * locale, and skip relations to content types the user cannot read.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function transformDocument(array $document, string $model, string $targetLocale, Ability $userAbility): array
    {
        $schema = $this->strapi->getModel($model) ?? ['attributes' => []];

        $relsByUid = $this->collectRelationsByUid($document, $schema);
        $preResolved = $this->resolveAllRelationsBatched($relsByUid, $targetLocale, $userAbility);

        return $this->processDocumentData($document, $schema, $preResolved);
    }
}
