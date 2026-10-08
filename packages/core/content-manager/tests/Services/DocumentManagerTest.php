<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\DocumentManager;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/document-manager.test.ts.
 *
 * Upstream mocks `strapi.documents().findMany`, `getDeepPopulateDraftCount` and `sumDraftCounts`;
 * here they run on a booted app (getstarted's kitchensink → tag relations).
 */
final class DocumentManagerTest extends TestCase
{
    private static Strapi $strapi;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    public function testCountManyEntriesDraftRelationsSumsTheCountsOfEveryDocument(): void
    {
        $tags = self::$strapi->documents('api::tag.tag');
        $draftTag = $tags->create(['data' => ['name' => 'draft tag']]);

        $sinks = self::$strapi->documents('api::kitchensink.kitchensink');
        $one = $sinks->create(['data' => ['short_text' => 'one', 'many_to_one_tag' => $draftTag['documentId']]]);
        $two = $sinks->create(['data' => ['short_text' => 'two', 'one_way_tag' => $draftTag['documentId']]]);

        $total = (new DocumentManager(self::$strapi))->countManyEntriesDraftRelations(
            [$one['documentId'], $two['documentId']],
            'api::kitchensink.kitchensink',
            'en',
        );

        self::assertSame(2, $total);
    }

    public function testCountManyEntriesDraftRelationsReturns0WhenTheContentTypeHasNoRelationsToCount(): void
    {
        $tag = self::$strapi->documents('api::tag.tag')->create(['data' => ['name' => 'no relation']]);

        // getstarted's homepage single type has no relation to a draft & publish type
        self::assertSame(0, (new DocumentManager(self::$strapi))->countManyEntriesDraftRelations([$tag['documentId']], 'api::homepage.homepage', 'en'));
    }

    public function testFindLocalesFiltersOnDocumentIdLocaleAndPublicationState(): void
    {
        $tags = self::$strapi->documents('api::tag.tag');
        $tag = $tags->create(['data' => ['name' => 'locales']]);
        $tags->publish(['documentId' => $tag['documentId']]);

        $manager = new DocumentManager(self::$strapi);

        self::assertCount(2, $manager->findLocales($tag['documentId'], 'api::tag.tag', []));
        self::assertCount(1, $manager->findLocales($tag['documentId'], 'api::tag.tag', ['isPublished' => true]));
        self::assertCount(1, $manager->findLocales([$tag['documentId']], 'api::tag.tag', ['isPublished' => false]));
        self::assertTrue($manager->exists('api::tag.tag', $tag['documentId']));
        self::assertFalse($manager->exists('api::tag.tag', 'missing'));
    }
}
