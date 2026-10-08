<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Draft;
use Strapi\ContentManager\Services\Utils\DraftRelations;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/utils/__tests__/draft-relations.test.ts.
 *
 * `sumDraftCounts` runs against a booted app (getstarted's `api::article.article` and
 * `api::tag.tag`, both with draft & publish) instead of a mocked `strapi.db.query`.
 */
final class DraftRelationsTest extends TestCase
{
    public function testIsBidirectionalManyToManyReturnsTrueForBidirectionalManyToMany(): void
    {
        self::assertTrue(DraftRelations::isBidirectionalManyToMany([
            'type' => 'relation',
            'relation' => 'manyToMany',
            'target' => 'api::tag.tag',
            'inversedBy' => 'articles',
        ]));
    }

    public function testIsBidirectionalManyToManyReturnsFalseForUnidirectionalManyToMany(): void
    {
        self::assertFalse(DraftRelations::isBidirectionalManyToMany([
            'type' => 'relation',
            'relation' => 'manyToMany',
            'target' => 'api::tag.tag',
        ]));
    }

    public function testMergeDraftRelationCounts(): void
    {
        self::assertSame(
            ['unpublishedRelations' => 3, 'draftM2mLinks' => 1],
            DraftRelations::mergeDraftRelationCounts(
                ['unpublishedRelations' => 1, 'draftM2mLinks' => 1],
                ['unpublishedRelations' => 2, 'draftM2mLinks' => 0],
            ),
        );
    }

    public function testSumDraftCountsSkipsLinksToPublishedDocuments(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'api::article.article' => ['attributes' => [
                'tag' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'api::tag.tag'],
            ]],
            'api::tag.tag' => ['options' => ['draftAndPublish' => false], 'attributes' => ['name' => ['type' => 'string']]],
        ]);

        // the target has no draft & publish: nothing to count, and no query is run
        self::assertSame(
            ['unpublishedRelations' => 0, 'draftM2mLinks' => 0],
            Draft::sumDraftCounts($strapi, ['tag' => ['documentId' => 'tag-1']], 'api::article.article'),
        );
    }

    public function testSumDraftCountsOnABootedApp(): void
    {
        $strapi = StubStrapi::boot();
        try {
            $published = $strapi->documents('api::tag.tag')->create(['data' => ['name' => 'published']]);
            $strapi->documents('api::tag.tag')->publish(['documentId' => $published['documentId']]);
            $draft = $strapi->documents('api::tag.tag')->create(['data' => ['name' => 'draft']]);

            $model = $strapi->getModel('api::kitchensink.kitchensink');
            self::assertNotNull($model);
            $attributes = array_filter(
                $model->attributes,
                static fn (array $attribute): bool => ($attribute['target'] ?? null) === 'api::tag.tag',
            );
            self::assertNotEmpty($attributes);
            $name = (string) array_key_first($attributes);
            $isM2m = DraftRelations::isBidirectionalManyToMany($attributes[$name]);
            $value = in_array($attributes[$name]['relation'] ?? null, ['oneToMany', 'manyToMany'], true)
                ? [['documentId' => $published['documentId']], ['documentId' => $draft['documentId']]]
                : ['documentId' => $draft['documentId']];

            $counts = Draft::sumDraftCounts($strapi, [$name => $value], 'api::kitchensink.kitchensink');

            self::assertSame($isM2m ? 0 : 1, $counts['unpublishedRelations']);
            self::assertSame($isM2m ? 1 : 0, $counts['draftM2mLinks']);
        } finally {
            $strapi->destroy();
        }
    }
}
