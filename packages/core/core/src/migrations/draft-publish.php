<?php

declare(strict_types=1);

namespace Strapi\Core\Migrations;

use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;

/**
 * Port of packages/core/core/src/migrations/draft-publish.ts.
 *
 * The stored `oldContentTypes` are the plain `schema.json`-shaped arrays saved in the core store
 * (`strapi_content_types_schema`), so `hasDraftAndPublish` is read from both shapes.
 *
 * @phpstan-import-type Input from Migrations
 */
final class DraftPublish
{
    private const BATCH_SIZE = 1000;

    /**
     * Enable draft and publish for content types: entries of a type that just enabled D&P are
     * published only; this migration clones them as drafts through `discardDraft`.
     *
     * @param Input $input
     */
    public static function enable(Strapi $strapi, array $input): void
    {
        $oldContentTypes = $input['oldContentTypes'] ?? null;
        if (!is_array($oldContentTypes) || $oldContentTypes === []) {
            return;
        }

        $strapi->db()->transaction(static function () use ($strapi, $oldContentTypes, $input): void {
            foreach ($input['contentTypes'] as $uid => $contentType) {
                $uid = (string) $uid;
                if (!isset($oldContentTypes[$uid])) {
                    continue;
                }

                if (!ContentTypes::hasDraftAndPublish($oldContentTypes[$uid]) && ContentTypes::hasDraftAndPublish($contentType)) {
                    $documents = $strapi->documents($uid);
                    $offset = 0;
                    do {
                        $batch = $strapi->db()->query($uid)->findMany([
                            'select' => ['id', 'documentId', 'locale'],
                            'where' => ['publishedAt' => ['$notNull' => true]],
                            'orderBy' => ['id' => 'asc'],
                            'limit' => self::BATCH_SIZE,
                            'offset' => $offset,
                        ]);
                        // only entries that do not already have a draft
                        foreach ($batch as $entry) {
                            $hasDraft = $strapi->db()->query($uid)->count(['where' => ['documentId' => $entry['documentId'], 'locale' => $entry['locale'] ?? null, 'publishedAt' => ['$null' => true]]]) > 0;
                            if (!$hasDraft) {
                                $documents->discardDraft(['documentId' => $entry['documentId'], 'locale' => $entry['locale'] ?? null]);
                            }
                        }
                        $offset += self::BATCH_SIZE;
                    } while (count($batch) === self::BATCH_SIZE);
                }
            }
        });
    }

    /**
     * If D&P was disabled remove unpublished content before sync.
     *
     * @param Input $input
     */
    public static function disable(Strapi $strapi, array $input): void
    {
        $oldContentTypes = $input['oldContentTypes'] ?? null;
        if (!is_array($oldContentTypes) || $oldContentTypes === []) {
            return;
        }

        foreach ($input['contentTypes'] as $uid => $contentType) {
            $uid = (string) $uid;
            if (!isset($oldContentTypes[$uid])) {
                continue;
            }

            if (ContentTypes::hasDraftAndPublish($oldContentTypes[$uid]) && !ContentTypes::hasDraftAndPublish($contentType)) {
                $strapi->db()->queryBuilder($uid)->delete()->where(['published_at' => null])->execute();
            }
        }
    }
}
