<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Homepage\Services;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Homepage\Services\Homepage;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/homepage/services/__tests__/homepage.test.ts.
 *
 * Upstream mocks the permission service, the core store query and `strapi.documents()`; here
 * the service runs on a booted app (getstarted) for a super admin, inside a request context.
 */
final class HomepageTest extends TestCase
{
    private static Strapi $strapi;

    /** @var array<string, mixed> */
    private static array $user;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
        self::$user = StubStrapi::createSuperAdmin(self::$strapi, 'homepage@strapi.io');
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    private static function inRequest(\Closure $fn): mixed
    {
        $ability = StubStrapi::userAbility(self::$strapi, self::$user);
        $ctx = StubStrapi::ctx(state: ['user' => self::$user, 'userAbility' => $ability]);

        return self::$strapi->requestContext()->run($ctx, $fn);
    }

    public function testQueryLastDocumentsReturnsAtMostFourDocumentsSortedByUpdatedAt(): void
    {
        foreach (['one', 'two', 'three'] as $name) {
            self::$strapi->documents('api::tag.tag')->create(['data' => ['name' => $name]]);
            self::$strapi->documents('api::category.category')->create(['data' => ['name' => "category {$name}"]]);
        }

        $service = Homepage::createHomepageService(self::$strapi);
        $result = self::inRequest(static fn (): array => $service->queryLastDocuments(['sort' => 'updatedAt:desc']));

        self::assertCount(4, $result);
        $updatedAt = array_column($result, 'updatedAt');
        $sorted = $updatedAt;
        rsort($sorted);
        self::assertSame($sorted, $updatedAt);
        foreach ($result as $document) {
            self::assertArrayHasKey('documentId', $document);
            self::assertArrayHasKey('contentTypeUid', $document);
            self::assertArrayHasKey('contentTypeDisplayName', $document);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $document['updatedAt']);
        }
        // documents are not duplicated even though the super admin has a read permission per locale/field set
        self::assertSame(count($result), count(array_unique(array_column($result, 'documentId'))));
    }

    public function testRecentlyUpdatedDocumentsCarryTheirStatus(): void
    {
        $tag = self::$strapi->documents('api::tag.tag')->create(['data' => ['name' => 'fresh']]);
        self::$strapi->documents('api::tag.tag')->publish(['documentId' => $tag['documentId']]);

        $service = Homepage::createHomepageService(self::$strapi);
        $result = self::inRequest(static fn (): array => $service->getRecentlyPublishedDocuments());

        $published = array_values(array_filter($result, static fn (array $d): bool => $d['documentId'] === $tag['documentId']));
        self::assertCount(1, $published);
        self::assertSame('published', $published[0]['status']);
        self::assertNotNull($published[0]['publishedAt']);
    }

    public function testGetCountDocumentsClassifiesDocuments(): void
    {
        $service = Homepage::createHomepageService(self::$strapi);
        $before = self::inRequest(static fn (): array => $service->getCountDocuments());

        $draft = self::$strapi->documents('api::tag.tag')->create(['data' => ['name' => 'count draft']]);
        $published = self::$strapi->documents('api::tag.tag')->create(['data' => ['name' => 'count published']]);
        self::$strapi->documents('api::tag.tag')->publish(['documentId' => $published['documentId']]);

        $after = self::inRequest(static fn (): array => $service->getCountDocuments());

        self::assertSame($before['draft'] + 1, $after['draft']);
        self::assertSame($before['published'] + 1, $after['published']);
        self::assertSame($before['modified'], $after['modified']);
        self::assertArrayHasKey('documentId', $draft);
    }
}
