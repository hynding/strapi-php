<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\DocumentService;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\DocumentService\DraftAndPublish;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;

/** Port of packages/core/core/src/services/document-service/__tests__/draft-and-publish.test.ts. */
final class DraftAndPublishTest extends TestCase
{
    private static function createContentType(bool $draftAndPublish): Schema
    {
        return SchemaFactory::contentType([
            'kind' => 'collectionType',
            'collectionName' => 'tests',
            'info' => ['displayName' => 'Test', 'singularName' => 'test', 'pluralName' => 'tests'],
            'options' => ['draftAndPublish' => $draftAndPublish],
            'attributes' => ['title' => ['type' => 'string']],
        ], 'api::test.test');
    }

    public function testSetStatusToDraftDefaultsAndForcesDraftForDpContentTypes(): void
    {
        $dp = self::createContentType(true);

        self::assertSame(['data' => [], 'status' => 'draft'], DraftAndPublish::setStatusToDraft($dp, ['data' => []]));
        self::assertSame(['data' => [], 'status' => 'draft'], DraftAndPublish::setStatusToDraft($dp, ['data' => [], 'status' => 'published']));
    }

    public function testSetStatusToDraftPreservesNonDpParams(): void
    {
        $nonDp = self::createContentType(false);

        self::assertSame(['data' => []], DraftAndPublish::setStatusToDraft($nonDp, ['data' => []]));
        self::assertSame(['data' => [], 'status' => 'published'], DraftAndPublish::setStatusToDraft($nonDp, ['data' => [], 'status' => 'published']));
    }

    public function testStatusToLookupAddsPublicationConstraintsWithoutTouchingOtherBranches(): void
    {
        $dp = self::createContentType(true);
        $params = ['status' => 'published', 'data' => ['title' => 'Draft'], 'lookup' => ['locale' => 'en']];

        $result = DraftAndPublish::statusToLookup($dp, $params);

        self::assertSame(['locale' => 'en', 'publishedAt' => ['$notNull' => true]], $result['lookup']);
        self::assertSame(['title' => 'Draft'], $result['data']);
        self::assertSame(['locale' => 'en'], $params['lookup']);

        $draft = DraftAndPublish::statusToLookup($dp, [...$params, 'status' => 'draft']);
        self::assertSame(['locale' => 'en', 'publishedAt' => ['$null' => true]], $draft['lookup']);

        self::assertSame($params, DraftAndPublish::statusToLookup(self::createContentType(false), $params));
    }

    public function testClearingPublicationTimestampsPreservesNestedData(): void
    {
        $dp = self::createContentType(true);
        $params = ['status' => 'draft', 'data' => ['title' => 'Draft', 'publishedAt' => '2026-01-01T00:00:00.000Z'], 'lookup' => ['locale' => 'en']];

        foreach ([DraftAndPublish::statusToData($dp, $params), DraftAndPublish::filterDataPublishedAt($params)] as $result) {
            self::assertSame(['title' => 'Draft', 'publishedAt' => null], $result['data']);
            self::assertSame(['locale' => 'en'], $result['lookup']);
        }
        self::assertSame('2026-01-01T00:00:00.000Z', $params['data']['publishedAt']);
    }

    public function testStatusToDataSetsPublishedAtForPublishedAndNonDp(): void
    {
        $dp = self::createContentType(true);
        $published = DraftAndPublish::statusToData($dp, ['status' => 'published', 'data' => ['title' => 'x']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $published['data']['publishedAt']);

        $nonDp = DraftAndPublish::statusToData(self::createContentType(false), ['data' => ['title' => 'x']]);
        self::assertNotNull($nonDp['data']['publishedAt']);

        $untouched = DraftAndPublish::statusToData($dp, ['data' => ['title' => 'x']]);
        self::assertArrayNotHasKey('publishedAt', $untouched['data']);
    }

    public function testDefaultStatus(): void
    {
        $dp = self::createContentType(true);
        self::assertSame('draft', DraftAndPublish::defaultStatus($dp, [])['status']);
        self::assertSame('draft', DraftAndPublish::defaultStatus($dp, ['status' => 'bogus'])['status']);
        self::assertSame('published', DraftAndPublish::defaultStatus($dp, ['status' => 'published'])['status']);
        self::assertSame(['status' => 'bogus'], DraftAndPublish::defaultStatus(self::createContentType(false), ['status' => 'bogus']));
    }
}
