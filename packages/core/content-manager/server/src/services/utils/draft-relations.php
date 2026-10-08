<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils;

/**
 * Port of server/src/services/utils/draft-relations.ts.
 *
 * @phpstan-type DraftRelationCounts array{unpublishedRelations: int, draftM2mLinks: int}
 */
final class DraftRelations
{
    /** @var DraftRelationCounts */
    public const array EMPTY_DRAFT_RELATION_COUNTS = [
        'unpublishedRelations' => 0,
        'draftM2mLinks' => 0,
    ];

    /**
     * Bidirectional manyToMany links share a join-table row and are kept on publish; they become
     * visible on the live site once the related entry is published. xToOne-style links are stripped.
     *
     * @param array<string, mixed> $attribute
     */
    public static function isBidirectionalManyToMany(array $attribute): bool
    {
        return ($attribute['relation'] ?? null) === 'manyToMany'
            && (!empty($attribute['inversedBy']) || !empty($attribute['mappedBy']));
    }

    /**
     * @param DraftRelationCounts $left
     * @param DraftRelationCounts $right
     * @return DraftRelationCounts
     */
    public static function mergeDraftRelationCounts(array $left, array $right): array
    {
        return [
            'unpublishedRelations' => $left['unpublishedRelations'] + $right['unpublishedRelations'],
            'draftM2mLinks' => $left['draftM2mLinks'] + $right['draftM2mLinks'],
        ];
    }
}
