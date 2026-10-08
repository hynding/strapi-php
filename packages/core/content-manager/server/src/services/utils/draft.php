<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/services/utils/draft.ts.
 *
 * @phpstan-type DraftRelationLinkRef array{targetUid: string, documentId: string, locale: string|null}
 * @phpstan-type CollectedDraftRelationLinks array{m2mLinks: list<DraftRelationLinkRef>, xToOneLinks: list<DraftRelationLinkRef>}
 */
final class Draft
{
    private static function isLocalizedContentType(Schema $model): bool
    {
        return ($model->pluginOptions['i18n']['localized'] ?? null) === true;
    }

    private static function toPublishedDocumentKey(string $documentId, ?string $locale): string
    {
        return $documentId . ':' . ($locale ?? '');
    }

    /**
     * @param CollectedDraftRelationLinks $left
     * @param CollectedDraftRelationLinks $right
     * @return CollectedDraftRelationLinks
     */
    private static function mergeCollectedLinks(array $left, array $right): array
    {
        return [
            'm2mLinks' => [...$left['m2mLinks'], ...$right['m2mLinks']],
            'xToOneLinks' => [...$left['xToOneLinks'], ...$right['xToOneLinks']],
        ];
    }

    /** @return DraftRelationLinkRef|null */
    private static function toDraftRelationLink(mixed $entry, string $targetUid, bool $targetIsLocalized, ?string $documentLocale): ?array
    {
        if (!is_array($entry) || empty($entry['documentId'])) {
            return null;
        }

        $locale = $entry['locale'] ?? $documentLocale;

        return [
            'targetUid' => $targetUid,
            'documentId' => (string) $entry['documentId'],
            'locale' => $targetIsLocalized ? (is_string($locale) ? $locale : null) : null,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @return CollectedDraftRelationLinks
     */
    private static function collectDraftRelationLinks(Strapi $strapi, array $entity, string $uid, ?string $documentLocale = null): array
    {
        $model = $strapi->getModel($uid);
        $collected = ['m2mLinks' => [], 'xToOneLinks' => []];
        if ($model === null) {
            return $collected;
        }
        $entityLocale = $entity['locale'] ?? $documentLocale;
        $locale = is_string($entityLocale) ? $entityLocale : null;

        foreach ($model->attributes as $attributeName => $attribute) {
            $value = $entity[$attributeName] ?? null;

            if ($value === null || $value === false || $value === '' || $value === 0) {
                continue;
            }

            switch ($attribute['type'] ?? null) {
                case 'relation':
                    if (!array_key_exists('target', $attribute)) {
                        break;
                    }

                    $targetModel = $strapi->getModel((string) $attribute['target']);
                    if ($targetModel === null || !ContentTypes::hasDraftAndPublish($targetModel)) {
                        break;
                    }

                    if ($attribute['target'] === $uid || !ContentTypes::isVisibleAttribute($model, (string) $attributeName)) {
                        break;
                    }

                    $targetIsLocalized = self::isLocalizedContentType($targetModel);
                    $relatedEntries = is_array($value) && array_is_list($value) ? $value : [$value];
                    $links = [];
                    foreach ($relatedEntries as $entry) {
                        $link = self::toDraftRelationLink($entry, (string) $attribute['target'], $targetIsLocalized, $locale);
                        if ($link !== null) {
                            $links[] = $link;
                        }
                    }

                    if ($links === []) {
                        break;
                    }

                    if (DraftRelations::isBidirectionalManyToMany($attribute)) {
                        $collected['m2mLinks'] = [...$collected['m2mLinks'], ...$links];
                    } else {
                        $collected['xToOneLinks'] = [...$collected['xToOneLinks'], ...$links];
                    }
                    break;
                case 'component':
                    foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $componentValue) {
                        if (is_array($componentValue)) {
                            $collected = self::mergeCollectedLinks(
                                $collected,
                                self::collectDraftRelationLinks($strapi, $componentValue, (string) $attribute['component'], $locale),
                            );
                        }
                    }
                    break;
                case 'dynamiczone':
                    foreach (is_array($value) ? $value : [] as $componentValue) {
                        if (is_array($componentValue)) {
                            $collected = self::mergeCollectedLinks(
                                $collected,
                                self::collectDraftRelationLinks($strapi, $componentValue, (string) ($componentValue['__component'] ?? ''), $locale),
                            );
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        return $collected;
    }

    /** @param list<DraftRelationLinkRef> $links */
    private static function countLinksToUnpublishedDocuments(Strapi $strapi, array $links): int
    {
        if ($links === []) {
            return 0;
        }

        /** @var array<string, list<DraftRelationLinkRef>> $linksByTarget */
        $linksByTarget = [];
        foreach ($links as $link) {
            $linksByTarget[$link['targetUid']][] = $link;
        }

        $total = 0;
        foreach ($linksByTarget as $targetUid => $targetLinks) {
            $targetModel = $strapi->getModel((string) $targetUid);
            $targetIsLocalized = $targetModel !== null && self::isLocalizedContentType($targetModel);
            $documentIds = array_values(array_unique(array_map(static fn (array $link): string => $link['documentId'], $targetLinks)));

            $publishedRows = $strapi->db()->query((string) $targetUid)->findMany([
                'select' => ['documentId', 'locale'],
                'where' => [
                    'documentId' => ['$in' => $documentIds],
                    'publishedAt' => ['$notNull' => true],
                ],
            ]);

            if (!$targetIsLocalized) {
                $publishedDocumentIds = array_flip(array_map(static fn (array $row): string => (string) $row['documentId'], $publishedRows));

                $total += count(array_filter($targetLinks, static fn (array $link): bool => !isset($publishedDocumentIds[$link['documentId']])));
                continue;
            }

            $publishedDocumentKeys = array_flip(array_map(
                static fn (array $row): string => self::toPublishedDocumentKey((string) $row['documentId'], isset($row['locale']) ? (string) $row['locale'] : null),
                $publishedRows,
            ));

            $total += count(array_filter(
                $targetLinks,
                static fn (array $link): bool => !isset($publishedDocumentKeys[self::toPublishedDocumentKey($link['documentId'], $link['locale'])]),
            ));
        }

        return $total;
    }

    /**
     * sumDraftCounts works recursively on the attributes of a model counting draft relations
     * that matter for publish warnings.
     *
     * - unpublishedRelations: xToOne / oneToMany style links stripped from the published version
     * - draftM2mLinks: bidirectional manyToMany links to documents without a published version
     *
     * @param array<string, mixed> $entity
     * @return array{unpublishedRelations: int, draftM2mLinks: int}
     */
    public static function sumDraftCounts(Strapi $strapi, array $entity, string $uid): array
    {
        ['m2mLinks' => $m2mLinks, 'xToOneLinks' => $xToOneLinks] = self::collectDraftRelationLinks($strapi, $entity, $uid);

        $draftM2mLinks = self::countLinksToUnpublishedDocuments($strapi, $m2mLinks);
        $unpublishedRelations = self::countLinksToUnpublishedDocuments($strapi, $xToOneLinks);

        return [
            'unpublishedRelations' => $unpublishedRelations,
            'draftM2mLinks' => $draftM2mLinks,
        ];
    }
}
