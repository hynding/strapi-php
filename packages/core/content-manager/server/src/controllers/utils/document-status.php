<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Utils;

/** Port of server/src/controllers/utils/document-status.ts. */
final class DocumentStatus
{
    /**
     * Index items by documentId for lookup
     *
     * @param array<array<string, mixed>> $items Array of items with documentId property
     * @return array<string, list<array<string, mixed>>> Map of documentId -> items array
     */
    public static function indexByDocumentId(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $key = $item['documentId'] ?? null;
            if ($key === null || $key === '') {
                continue;
            }

            $map[(string) $key][] = $item;
        }

        return $map;
    }
}
