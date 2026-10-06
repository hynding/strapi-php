<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Utils;

use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;

/** Port of transform/relations/utils/dp.ts. */
final class Dp
{
    /**
     * @param array{documentId?: mixed, status?: string|null} $relation
     * @param array{targetUid: string, sourceUid: string, sourceStatus?: string|null} $opts
     * @return list<'draft'|'published'>
     */
    public static function getRelationTargetStatus(Strapi $strapi, array $relation, array $opts): array
    {
        // Ignore if the target content type does not have draft and publish enabled
        $targetContentType = $strapi->getModel($opts['targetUid']);
        $sourceContentType = $strapi->getModel($opts['sourceUid']);

        $targetHasDP = ContentTypes::hasDraftAndPublish($targetContentType);
        $sourceHasDP = ContentTypes::hasDraftAndPublish($sourceContentType);

        if (!$targetHasDP) {
            return ['published'];
        }

        // If both source and target have DP enabled, connect it to the same status as the source status
        $sourceStatus = $opts['sourceStatus'] ?? null;
        if ($sourceHasDP && $sourceStatus !== null) {
            return [$sourceStatus === 'published' ? 'published' : 'draft'];
        }

        // Use the status from the relation if it's set
        if (!empty($relation['status'])) {
            return $relation['status'] === 'published' ? ['published'] : ['draft'];
        }

        // If DP is disabled and relation does not specify any status, connect to both draft and published versions
        if (!$sourceHasDP) {
            return ['draft', 'published'];
        }

        // Default to draft as a fallback
        return ['draft'];
    }
}
