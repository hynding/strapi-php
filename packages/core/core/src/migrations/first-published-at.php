<?php

declare(strict_types=1);

namespace Strapi\Core\Migrations;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/migrations/first-published-at.ts. The experimental
 * `firstPublishedAt` attribute is only added to schemas when the `firstPublishedAt` option is on;
 * the migration is a no-op for every other content type.
 *
 * @phpstan-import-type Input from Migrations
 */
final class FirstPublishedAt
{
    /** @param Input $input */
    public static function enable(Strapi $strapi, array $input): void
    {
        $oldContentTypes = $input['oldContentTypes'] ?? null;
        if (!is_array($oldContentTypes) || $oldContentTypes === []) {
            return;
        }

        foreach ($input['contentTypes'] as $uid => $contentType) {
            $uid = (string) $uid;
            if (!isset($oldContentTypes[$uid]) || !$contentType->hasAttribute('firstPublishedAt')) {
                continue;
            }

            $content = $strapi->db()->queryBuilder($uid)->select('*')->execute();

            $grouped = [];
            foreach ($content as $item) {
                $grouped[($item['documentId'] ?? '') . '-' . ($item['locale'] ?? '')][] = $item;
            }

            foreach ($grouped as $items) {
                if (count($items) <= 1) {
                    continue;
                }
                if (($items[0]['firstPublishedAt'] ?? null) !== null && ($items[1]['firstPublishedAt'] ?? null) !== null) {
                    continue;
                }

                $published = null;
                foreach ($items as $item) {
                    if (($item['publishedAt'] ?? null) !== null) {
                        $published = $item;
                        break;
                    }
                }
                if ($published === null) {
                    continue;
                }

                $strapi->db()->queryBuilder($uid)
                    ->update(['firstPublishedAt' => $published['publishedAt']])
                    ->where(['documentId' => $published['documentId'], 'locale' => $published['locale'] ?? null])
                    ->execute();
            }
        }
    }
}
