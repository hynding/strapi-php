<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils;

use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Relations;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\VisitorOptions;

/**
 * Port of server/src/services/utils/populate.ts.
 *
 * Upstream's module-level caches (`validationPopulateCache`, `draftCountPopulateCache`,
 * `deepPopulateCache`) live for the lifetime of the Node process, i.e. of one Strapi instance;
 * here they are kept per Strapi instance.
 *
 * Populate options: `initialPopulate` is only "provided" when the key is present (JS
 * `undefined` = key absent).
 *
 * @phpstan-type PopulateOptions array{initialPopulate?: mixed, countMany?: bool, countOne?: bool, maxLevel?: int|float}
 */
final class Populate
{
    /** @var \WeakMap<Strapi, array<string, array<string, mixed>>>|null */
    private static ?\WeakMap $validationPopulateCache = null;

    /** @var \WeakMap<Strapi, array<string, array{populate: array<string, mixed>, hasRelations: bool}>>|null */
    private static ?\WeakMap $draftCountPopulateCache = null;

    /** @var \WeakMap<Strapi, array<string, mixed>>|null */
    private static ?\WeakMap $deepPopulateCache = null;

    private static function isLocalizedContentType(Schema $model): bool
    {
        return ($model->pluginOptions['i18n']['localized'] ?? null) === true;
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isMorphToRelation(?array $attribute): bool
    {
        return self::isRelation($attribute) && str_contains((string) ($attribute['relation'] ?? ''), 'morphTo');
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isMedia(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'media';
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isRelation(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'relation';
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isComponent(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'component';
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isDynamicZone(?array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'dynamiczone';
    }

    /**
     * Populate the model for relation
     *
     * @param array<string, mixed> $attribute
     * @param PopulateOptions $options
     */
    private static function getPopulateForRelation(Strapi $strapi, array $attribute, Schema $model, string $attributeName, array $options): mixed
    {
        $isManyRelation = Relations::isAnyToMany($attribute);

        // Use initialPopulate when explicitly provided (including `false` to suppress population)
        if (array_key_exists('initialPopulate', $options)) {
            return $options['initialPopulate'];
        }

        // If populating localizations attribute, also include validatable fields
        // Mainly needed for bulk locale publishing, so the Client has all the information necessary to perform validations
        if ($attributeName === 'localizations') {
            $validationPopulate = self::getPopulateForValidation($strapi, $model->uid);

            // upstream: `{ populate: undefined }` when nothing needs validation
            return [
                'populate' => $validationPopulate['populate'] ?? [],
            ];
        }

        // always populate createdBy, updatedBy, localizations etc.
        if (!ContentTypes::isVisibleAttribute($model, $attributeName)) {
            return true;
        }

        $countMany = $options['countMany'] ?? false;
        $countOne = $options['countOne'] ?? false;
        if (($isManyRelation && $countMany) || (!$isManyRelation && $countOne)) {
            return ['count' => true];
        }

        return true;
    }

    /**
     * Populate the model for Dynamic Zone components
     *
     * @param array<string, mixed> $attribute
     * @param PopulateOptions $options
     * @return array{on: array<string, array{populate: array<string, mixed>}>}
     */
    private static function getPopulateForDZ(Strapi $strapi, array $attribute, array $options, int $level): array
    {
        // Use fragments to populate the dynamic zone components
        $populatedComponents = [];
        foreach (is_array($attribute['components'] ?? null) ? $attribute['components'] : [] as $componentUID) {
            $populatedComponents[(string) $componentUID] = [
                'populate' => self::getDeepPopulate($strapi, (string) $componentUID, $options, $level + 1),
            ];
        }

        return ['on' => $populatedComponents];
    }

    /**
     * Get the populated value based on the type of the attribute
     *
     * @param PopulateOptions $options
     * @return array<string, mixed>
     */
    private static function getPopulateFor(Strapi $strapi, string $attributeName, Schema $model, array $options, int $level): array
    {
        $attribute = $model->attributes[$attributeName];

        switch ($attribute['type'] ?? null) {
            case 'relation':
                return [
                    $attributeName => self::getPopulateForRelation($strapi, $attribute, $model, $attributeName, $options),
                ];
            case 'component':
                return [
                    $attributeName => [
                        'populate' => self::getDeepPopulate($strapi, (string) $attribute['component'], $options, $level + 1),
                    ],
                ];
            case 'media':
                return [
                    $attributeName => [
                        'populate' => [
                            'folder' => true,
                        ],
                    ],
                ];
            case 'dynamiczone':
                return [
                    $attributeName => self::getPopulateForDZ($strapi, $attribute, $options, $level),
                ];
            default:
                return [];
        }
    }

    /**
     * Deeply populate a model based on UID
     *
     * @param PopulateOptions $options
     * @return array<string, mixed>
     */
    public static function getDeepPopulate(Strapi $strapi, string $uid, array $options = [], int $level = 1): array
    {
        $initialPopulate = array_key_exists('initialPopulate', $options) && $options['initialPopulate'] !== null
            ? $options['initialPopulate']
            : [];
        $countMany = $options['countMany'] ?? false;
        $countOne = $options['countOne'] ?? false;
        $maxLevel = $options['maxLevel'] ?? INF;

        if ($level > $maxLevel) {
            return [];
        }

        $model = $strapi->getModel($uid);

        if ($model === null) {
            return [];
        }

        $populateAcc = [];
        foreach (array_keys($model->attributes) as $attributeName) {
            $attributeOptions = ['countMany' => $countMany, 'countOne' => $countOne, 'maxLevel' => $maxLevel];
            if (is_array($initialPopulate) && array_key_exists($attributeName, $initialPopulate)) {
                $attributeOptions['initialPopulate'] = $initialPopulate[$attributeName];
            }

            $populateAcc = self::mergePopulate(
                $populateAcc,
                self::getPopulateFor($strapi, (string) $attributeName, $model, $attributeOptions, $level),
            );
        }

        return $populateAcc;
    }

    /**
     * lodash/fp `merge(a, b)`: deep merge where `b` wins; a scalar in `b` replaces an object in `a`
     * (unlike {@see Objects::merge()}, a `null` in `b` is kept).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private static function mergePopulate(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = self::mergePopulate($a[$key], $value);
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }

    /**
     * Deeply populate a model based on UID. Only populating fields that require validation.
     *
     * @return array<string, mixed>
     */
    public static function getPopulateForValidation(Strapi $strapi, string $uid): array
    {
        self::$validationPopulateCache ??= new \WeakMap();
        $cache = self::$validationPopulateCache[$strapi] ?? [];
        if (isset($cache[$uid])) {
            return $cache[$uid];
        }

        $model = $strapi->getModel($uid);
        if ($model === null) {
            return [];
        }

        $populateAcc = [];
        foreach ($model->attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;

            if (ContentTypes::isScalarAttribute($attribute)) {
                // If the scalar attribute requires validation, add it to the fields array
                if (ContentTypes::getDoesAttributeRequireValidation($attribute) && !ContentTypes::isPrivateAttribute($model, $attributeName)) {
                    $populateAcc['fields'] ??= [];
                    $populateAcc['fields'][] = $attributeName;
                }
                continue;
            }

            if (self::isMedia($attribute)) {
                if (ContentTypes::getDoesAttributeRequireValidation($attribute) && !ContentTypes::isPrivateAttribute($model, $attributeName)) {
                    $populateAcc['populate'] ??= [];
                    $populateAcc['populate'][$attributeName] = [
                        'populate' => [
                            'folder' => true,
                        ],
                    ];
                    continue;
                }
            }

            if (self::isComponent($attribute)) {
                // Get the validation result for this component
                $componentResult = self::getPopulateForValidation($strapi, (string) $attribute['component']);

                if ($componentResult !== []) {
                    $populateAcc['populate'] ??= [];
                    $populateAcc['populate'][$attributeName] = $componentResult;
                }

                continue;
            }

            if (self::isDynamicZone($attribute)) {
                // Handle dynamic zone components
                $componentsResult = [];
                foreach (is_array($attribute['components'] ?? null) ? $attribute['components'] : [] as $componentUID) {
                    // Get validation populate for this component
                    $componentResult = self::getPopulateForValidation($strapi, (string) $componentUID);

                    // Only include component if it has fields requiring validation
                    if ($componentResult !== []) {
                        $componentsResult[(string) $componentUID] = $componentResult;
                    }
                }

                // Only add to populate if we have components requiring validation
                if ($componentsResult !== []) {
                    $populateAcc['populate'] ??= [];
                    $populateAcc['populate'][$attributeName] = ['on' => $componentsResult];
                }
            }
        }

        $cache[$uid] = $populateAcc;
        self::$validationPopulateCache[$strapi] = $cache;

        return $populateAcc;
    }

    /**
     * getDeepPopulateDraftCount works recursively on the attributes of a model
     * creating a populated object to count all the unpublished relations within the model
     * These relations can be direct to this content type or contained within components/dynamic zones
     *
     * @return array{populate: array<string, mixed>, hasRelations: bool}
     */
    public static function getDeepPopulateDraftCount(Strapi $strapi, string $uid): array
    {
        self::$draftCountPopulateCache ??= new \WeakMap();
        $cache = self::$draftCountPopulateCache[$strapi] ?? [];
        if (isset($cache[$uid])) {
            return $cache[$uid];
        }

        $model = $strapi->getModel($uid);
        if ($model === null) {
            return ['populate' => [], 'hasRelations' => false];
        }
        $hasRelations = false;

        $populateAcc = [];
        foreach ($model->attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;

            switch ($attribute['type'] ?? null) {
                case 'relation':
                    // TODO: Support polymorphic relations
                    $isMorphRelation = str_starts_with(strtolower((string) ($attribute['relation'] ?? '')), 'morph');
                    if ($isMorphRelation) {
                        break;
                    }

                    // Skip relations to content types without draft & publish,
                    // as they don't have a publishedAt attribute and can't have drafts
                    if (!array_key_exists('target', $attribute)) {
                        break;
                    }

                    $targetModel = $strapi->getModel((string) $attribute['target']);
                    if ($targetModel === null || !ContentTypes::hasDraftAndPublish($targetModel)) {
                        break;
                    }

                    // Self-referential relations are preserved on publish (see self-referential-relations.ts).
                    if ($attribute['target'] === $uid) {
                        break;
                    }

                    if (ContentTypes::isVisibleAttribute($model, $attributeName)) {
                        // Draft entries link to draft rows of related documents. Populate documentId/locale
                        // so we can distinguish truly unpublished targets from published documents that
                        // still have a draft row (those links are kept on publish for M2M, or remapped for xToOne).
                        $fields = ['documentId'];
                        if (self::isLocalizedContentType($targetModel)) {
                            $fields[] = 'locale';
                        }
                        $populateAcc[$attributeName] = [
                            'fields' => $fields,
                            'filters' => [ContentTypes::PUBLISHED_AT_ATTRIBUTE => ['$null' => true]],
                        ];
                        $hasRelations = true;
                    }
                    break;
                case 'component':
                    ['populate' => $populate, 'hasRelations' => $childHasRelations] = self::getDeepPopulateDraftCount($strapi, (string) $attribute['component']);
                    if ($childHasRelations) {
                        $populateAcc[$attributeName] = [
                            'populate' => $populate,
                        ];
                        $hasRelations = true;
                    }
                    break;
                case 'dynamiczone':
                    $dzPopulateFragment = [];
                    foreach (is_array($attribute['components'] ?? null) ? $attribute['components'] : [] as $componentUID) {
                        ['populate' => $componentPopulate, 'hasRelations' => $componentHasRelations] = self::getDeepPopulateDraftCount($strapi, (string) $componentUID);

                        if ($componentHasRelations) {
                            $hasRelations = true;
                            $dzPopulateFragment[(string) $componentUID] = ['populate' => $componentPopulate];
                        }
                    }

                    if ($dzPopulateFragment !== []) {
                        $populateAcc[$attributeName] = ['on' => $dzPopulateFragment];
                    }
                    break;
                default:
            }
        }

        $result = ['populate' => $populateAcc, 'hasRelations' => $hasRelations];
        $cache[$uid] = $result;
        self::$draftCountPopulateCache[$strapi] = $cache;

        return $result;
    }

    /**
     * Create a Strapi populate object which populates all attribute fields of a Strapi query.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public static function getQueryPopulate(Strapi $strapi, string $uid, array $query): array
    {
        $populateQuery = [];

        QueryFilters::traverse(
            static function (VisitorOptions $options) use (&$populateQuery): void {
                $attribute = $options->attribute;

                // TODO: handle dynamic zones and morph relations
                if ($attribute === null || self::isDynamicZone($attribute) || self::isMorphToRelation($attribute)) {
                    return;
                }

                // Populate all relations, components and media
                if (self::isRelation($attribute) || self::isMedia($attribute) || self::isComponent($attribute)) {
                    $populatePath = str_replace('.', '.populate.', (string) $options->path->attribute);
                    $populateQuery = Objects::merge($populateQuery, Objects::set([], $populatePath, []));
                }
            },
            ['schema' => $strapi->getModel($uid), 'getModel' => static fn (string $modelUid) => $strapi->getModel($modelUid)],
            $query,
        );

        return $populateQuery;
    }

    /** @return array<string, mixed>|null */
    public static function buildDeepPopulate(Strapi $strapi, string $uid): ?array
    {
        self::$deepPopulateCache ??= new \WeakMap();
        $cache = self::$deepPopulateCache[$strapi] ?? [];
        if (isset($cache[$uid])) {
            return $cache[$uid];
        }

        $result = Utils::getService($strapi, 'populate-builder')($uid)
            ->populateDeep(INF)
            ->countRelations()
            ->build();

        $cache[$uid] = $result;
        self::$deepPopulateCache[$strapi] = $cache;

        return $result;
    }

    /**
     * Restrict localizations populate to only metadata fields for localized content types.
     * Returns an empty object for non-localized content types.
     *
     * By default, localizations are deeply populated which includes all relations and
     * components for every locale — this is expensive and unnecessary for CM responses.
     * The CM only needs these fields from localizations:
     * - locale: to identify which locales exist
     * - documentId: to link to the localized document
     * - publishedAt: to determine published/draft status
     * - updatedAt: to support the modified state indicator in the UI
     *
     * @return array<string, mixed>
     */
    public static function getPopulateForLocalizations(Strapi $strapi, string $model): array
    {
        $modelSchema = $strapi->getModel($model);
        if ($modelSchema !== null && !empty($modelSchema->pluginOptions['i18n']['localized'])) {
            return ['localizations' => ['fields' => ['locale', 'documentId', 'publishedAt', 'updatedAt']]];
        }

        return [];
    }

    /** Clears the per-instance caches (tests replace models between cases). */
    public static function clearCaches(): void
    {
        self::$validationPopulateCache = null;
        self::$draftCountPopulateCache = null;
        self::$deepPopulateCache = null;
    }
}
