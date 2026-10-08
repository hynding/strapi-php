<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\ContentManager\Services\PopulateBuilder;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Port of server/src/services/ai-localizations.ts (`createAILocalizationsService`,
 * `mergeUnsupportedFields`).
 *
 * Upstream starts the generation from the document-service middleware without awaiting it;
 * everything is synchronous here, so it runs before the middleware returns (errors are logged,
 * as upstream's `.catch`).
 */
final class AiLocalizations
{
    private const array UNSUPPORTED_ATTRIBUTE_TYPES = [
        'media',
        'relation',
        'boolean',
        'enumeration',
    ];

    private const array IGNORED_FIELDS = [
        'id',
        'documentId',
        'createdAt',
        'updatedAt',
        'publishedAt',
        'locale',
        'updatedBy',
        'createdBy',
        'localizations',
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isLocalizedAttribute(?array $attribute): bool
    {
        return ($attribute['pluginOptions']['i18n']['localized'] ?? null) === true;
    }

    /**
     * Deep merge where target values take priority over source values.
     * Arrays are merged by index to align repeatable component / dynamic zone items.
     *
     * @param array<array-key, mixed> $source
     * @param array<array-key, mixed> $target
     * @return array<array-key, mixed>
     */
    public static function deepMerge(array $source, array $target): array
    {
        $result = $source;

        foreach ($target as $key => $targetVal) {
            $sourceVal = $source[$key] ?? null;

            if (is_array($targetVal) && array_is_list($targetVal) && is_array($sourceVal) && array_is_list($sourceVal)) {
                $result[$key] = array_map(static function (mixed $item, int $i) use ($sourceVal): mixed {
                    if (is_array($item) && is_array($sourceVal[$i] ?? null)) {
                        return self::deepMerge($sourceVal[$i], $item);
                    }

                    return $item;
                }, $targetVal, array_keys($targetVal));
            } elseif (
                is_array($targetVal) && $targetVal !== [] && !array_is_list($targetVal)
                && is_array($sourceVal) && $sourceVal !== [] && !array_is_list($sourceVal)
            ) {
                $result[$key] = self::deepMerge($sourceVal, $targetVal);
            } else {
                $result[$key] = $targetVal;
            }
        }

        return $result;
    }

    /**
     * Merges unsupported field types (media, boolean, enumeration, relation)
     * from a source document into the target data object.
     *
     * Uses traverseEntity to walk the source document and extract only unsupported fields,
     * then deep-merges the AI-translated target data on top so translated values take priority.
     *
     * @param array<string, mixed> $targetData
     * @param array<string, mixed>|null $sourceDoc
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     * @return array<string, mixed>
     */
    public static function mergeUnsupportedFields(array $targetData, ?array $sourceDoc, Schema $schema, callable $getModel): array
    {
        if ($sourceDoc === null) {
            return $targetData;
        }

        // Track paths of relation/media fields so traverseEntity's recursion
        // into those fields doesn't strip internal fields like `id` or `url`.
        $preservedPaths = [];

        // Use traverseEntity to extract only unsupported fields from the source document.
        // traverseEntity handles component and dynamic zone recursion automatically.
        $unsupportedFieldsOnly = TraverseEntity::traverse(
            static function (VisitorOptions $options, VisitorUtils $utils) use (&$preservedPaths): void {
                $key = $options->key;
                $attribute = $options->attribute;
                $raw = $options->path->raw;

                // If we're inside a relation or media subtree, preserve everything.
                $isInsidePreservedSubtree = false;
                if ($raw !== null) {
                    foreach (array_keys($preservedPaths) as $pp) {
                        if (str_starts_with($raw, "{$pp}.")) {
                            $isInsidePreservedSubtree = true;
                            break;
                        }
                    }
                }
                if ($isInsidePreservedSubtree) {
                    $preservedPaths[(string) $raw] = true;

                    return;
                }

                if (in_array($key, self::IGNORED_FIELDS, true)) {
                    $utils->remove($key);

                    return;
                }

                // Keep fields with no schema attribute (e.g. __component in dynamic zones)
                if ($attribute === null) {
                    return;
                }

                $type = $attribute['type'] ?? null;

                // Mark relation and media subtrees as preserved so their internal
                // fields (id, url, etc.) are not removed during recursion
                if ($type === 'media' || $type === 'relation') {
                    $preservedPaths[(string) $raw] = true;

                    return;
                }

                // Keep other unsupported attribute types (boolean, enumeration)
                if (in_array($type, self::UNSUPPORTED_ATTRIBUTE_TYPES, true)) {
                    return;
                }

                // Keep components and dynamic zones — traverseEntity recurses into them
                if ($type === 'component' || $type === 'dynamiczone') {
                    return;
                }

                // Remove supported (translatable) fields
                $utils->remove($key);
            },
            ['schema' => $schema, 'getModel' => $getModel],
            $sourceDoc,
        );

        // Deep merge: AI-translated target takes priority over source unsupported fields
        /** @var array<string, mixed> $merged */
        $merged = self::deepMerge(is_array($unsupportedFieldsOnly) ? $unsupportedFieldsOnly : [], $targetData);

        return $merged;
    }

    // Async upstream to avoid changing the signature later (there will be a db check in the future)
    public function isEnabled(): bool
    {
        if (Utils::aiTranslations($this->strapi)->hasProvider() === false) {
            return false;
        }
        $aiSettings = Utils::settings($this->strapi)->getSettings();

        return ($aiSettings['aiLocalizations'] ?? null) === true;
    }

    /**
     * Checks if there are localizations that need to be generated for the given document,
     * and if so, calls the AI service and saves the results to the database.
     * Works for both single and collection types, on create and update.
     *
     * @param array{model: string, document: array<string, mixed>|null} $params
     */
    public function generateDocumentLocalizations(array $params): void
    {
        $model = $params['model'];
        $document = $params['document'] ?? [];

        $isFeatureEnabled = $this->isEnabled();
        if (!$isFeatureEnabled) {
            return;
        }

        $schema = $this->strapi->getModel($model);
        if ($schema === null) {
            return;
        }
        $localeService = Utils::locales($this->strapi);

        // No localizations needed for content types with i18n disabled
        $isLocalizedContentType = Utils::contentTypes($this->strapi)->isLocalizedContentType($schema);
        if (!$isLocalizedContentType) {
            return;
        }

        // Don't trigger localizations if the update is on a derived locale, only do it on the default
        $defaultLocale = $localeService->getDefaultLocale();
        if (($document['locale'] ?? null) !== $defaultLocale) {
            return;
        }

        $documentId = $document['documentId'] ?? null;

        if (!is_string($documentId) || $documentId === '') {
            $this->strapi->log()->warning("AI Localizations: missing documentId for {$schema->uid}");

            return;
        }

        $sourceLocale = (string) $document['locale'];

        $localizedRoots = [];

        $translateableContent = TraverseEntity::traverse(
            static function (VisitorOptions $options, VisitorUtils $utils) use (&$localizedRoots): void {
                $key = $options->key;
                $attribute = $options->attribute;
                $type = $attribute['type'] ?? null;

                if (in_array($key, self::IGNORED_FIELDS, true)) {
                    $utils->remove($key);

                    return;
                }
                $hasLocalizedOption = $attribute !== null && self::isLocalizedAttribute($attribute);
                if ($attribute !== null && in_array($type, self::UNSUPPORTED_ATTRIBUTE_TYPES, true)) {
                    $utils->remove($key);

                    return;
                }

                // If this field is localized, keep it (and mark as localized root if component/dz)
                if ($hasLocalizedOption) {
                    // If it's a component/dynamiczone, add to the set
                    if (in_array($type, ['component', 'dynamiczone'], true)) {
                        $localizedRoots[(string) $options->path->raw] = true;
                    }

                    return; // keep
                }

                if ($options->parent !== null && isset($localizedRoots[(string) $options->parent->path->raw])) {
                    // If parent exists in the localized roots set, keep it
                    // If this is also a component/dz, propagate the localized root flag
                    if (in_array($type ?? '', ['component', 'dynamiczone'], true)) {
                        $localizedRoots[(string) $options->path->raw] = true;
                    }

                    return; // keep
                }

                // Otherwise, remove the field
                $utils->remove($key);
            },
            ['schema' => $schema, 'getModel' => $this->strapi->getModel(...)],
            $document,
        );
        $translateableContent = is_array($translateableContent) ? $translateableContent : [];

        if (count($translateableContent) === 0) {
            $this->strapi->log()->info("AI Localizations: no translatable content for {$schema->uid} document {$documentId}");

            return;
        }

        $localesList = $localeService->find();
        $targetLocales = array_values(array_map(
            static fn (array $l): string => (string) ($l['code'] ?? ''),
            array_filter($localesList, static fn (array $l): bool => ($l['code'] ?? null) !== $sourceLocale),
        ));

        if (count($targetLocales) === 0) {
            $this->strapi->log()->info("AI Localizations: no target locales for {$schema->uid} document {$documentId}");

            return;
        }

        $aiLocalizationJobsService = Utils::aiLocalizationJobs($this->strapi);

        $aiLocalizationJobsService->upsertJobForDocument([
            'contentType' => $model,
            'documentId' => $documentId,
            'sourceLocale' => $sourceLocale,
            'targetLocales' => $targetLocales,
            'status' => 'processing',
        ]);

        /**
         * Provide a schema to the LLM so that we can give it instructions about how to handle each
         * type of attribute. Only keep essential schema data to avoid cluttering the context.
         * Ignore fields that don't need to be localized.
         * TODO: also provide a schema of all the referenced components
         */
        $minimalContentTypeSchema = [];
        foreach ($schema->attributes as $key => $attr) {
            $isLocalized = self::isLocalizedAttribute($attr);
            $isSupportedType = !in_array($attr['type'] ?? null, self::UNSUPPORTED_ATTRIBUTE_TYPES, true);
            if (!$isLocalized || !$isSupportedType) {
                continue;
            }
            $minimalAttribute = ['type' => $attr['type'] ?? null];
            if (($attr['type'] ?? null) === 'component') {
                $minimalAttribute['repeatable'] = $attr['repeatable'] ?? false;
            }
            if (is_int($attr['maxLength'] ?? null) || is_float($attr['maxLength'] ?? null)) {
                $minimalAttribute['maxLength'] = $attr['maxLength'];
            }
            if (is_int($attr['minLength'] ?? null) || is_float($attr['minLength'] ?? null)) {
                $minimalAttribute['minLength'] = $attr['minLength'];
            }
            $minimalContentTypeSchema[$key] = $minimalAttribute;
        }

        try {
            $aiResult = Utils::aiTranslations($this->strapi)->generateTranslations([
                'sourceLocale' => $sourceLocale,
                'targetLocales' => $targetLocales,
                'content' => $translateableContent,
                'contentTypeSchema' => $minimalContentTypeSchema,
            ]);
        } catch (\Throwable $error) {
            $aiLocalizationJobsService->upsertJobForDocument([
                'documentId' => $documentId,
                'contentType' => $model,
                'sourceLocale' => $sourceLocale,
                'targetLocales' => $targetLocales,
                'status' => 'failed',
            ]);

            throw $error;
        }

        // Use populate-builder service for deep populate to fetch all nested fields
        $populateBuilderService = $this->strapi->plugin('content-manager')->service('populate-builder');
        assert($populateBuilderService instanceof PopulateBuilder);
        $deepPopulate = $populateBuilderService($model)->populateDeep(INF)->build();
        $getModelBound = $this->strapi->getModel(...);

        // Fetch the source document with all fields populated (for new locales that don't exist yet)
        $sourceDocWithAllFields = $this->strapi->documents($model)->findOne([
            'documentId' => $documentId,
            'locale' => $sourceLocale,
            'populate' => $deepPopulate,
        ]);

        $failedLocales = [];
        foreach ($aiResult['localizations'] as $localization) {
            $content = $localization['content'];
            $locale = $localization['locale'];

            try {
                $derivedDoc = $this->strapi->documents($model)->findOne([
                    'documentId' => $documentId,
                    'locale' => $locale,
                    'populate' => $deepPopulate,
                ]);

                $sourceForUnsupportedFields = $derivedDoc ?? $sourceDocWithAllFields;
                $mergedData = self::mergeUnsupportedFields($content, $sourceForUnsupportedFields, $schema, $getModelBound);

                $this->strapi->documents($model)->update([
                    'documentId' => $documentId,
                    'locale' => $locale,
                    'fields' => [],
                    'data' => $mergedData,
                ]);
            } catch (\Throwable $reason) {
                $failedLocales[] = $locale;
                $this->strapi->log()->error("AI Localizations: failed to save locale \"{$locale}\" for {$model} document {$documentId}: {$reason->getMessage()}");
            }
        }

        $aiLocalizationJobsService->upsertJobForDocument([
            'documentId' => $documentId,
            'contentType' => $model,
            'sourceLocale' => $sourceLocale,
            'targetLocales' => $targetLocales,
            'status' => count($failedLocales) > 0 ? 'failed' : 'completed',
        ]);
    }

    public function setupMiddleware(): void
    {
        $this->strapi->documentService()->use(function (array $context, callable $next): mixed {
            $result = $next($context);

            // Only trigger for the allowed actions
            if (!in_array($context['action'] ?? null, ['create', 'update'], true)) {
                return $result;
            }

            // Check if AI localizations are enabled before triggering
            $isEnabled = $this->isEnabled();
            if (!$isEnabled) {
                return $result;
            }

            $contentType = $context['contentType'] ?? null;

            // Upstream does not await: localizations are generated "in the background"
            try {
                Utils::aiLocalizations($this->strapi)->generateDocumentLocalizations([
                    'model' => $contentType instanceof Schema ? $contentType->uid : (string) ($context['uid'] ?? ''),
                    'document' => is_array($result) ? $result : null,
                ]);
            } catch (\Throwable $error) {
                $this->strapi->log()->error('AI Localizations generation failed', ['error' => $error]);
            }

            return $result;
        });
    }
}
