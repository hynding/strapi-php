<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Uid;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/uid.test.ts.
 *
 * Upstream mocks `strapi.documents().findMany/count`; here the documents live in a booted app:
 * getstarted's `api::kitchensink.kitchensink` (`slug`: uid with targetField `short_text`, model
 * name `kitchensink`) stands in for the fake `my-model`.
 */
final class UidTest extends TestCase
{
    private const string UID = 'api::kitchensink.kitchensink';

    private static Strapi $strapi;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    protected function setUp(): void
    {
        self::$strapi->db()->query(self::UID)->deleteMany();
    }

    private static function service(): Uid
    {
        return new Uid(self::$strapi);
    }

    private static function createWithSlug(string $slug): void
    {
        self::$strapi->documents(self::UID)->create(['data' => ['slug' => $slug]]);
    }

    public function testUsesModelNameIfNoTargetFieldValueIsSet(): void
    {
        $uid = self::service()->generateUIDField(['contentTypeUID' => self::UID, 'field' => 'slug', 'data' => []]);

        self::assertSame('kitchensink', $uid);
    }

    public function testUsesTargetFieldValueForGeneration(): void
    {
        self::createWithSlug('test-title');

        $uid = self::service()->generateUIDField(['contentTypeUID' => self::UID, 'field' => 'slug', 'data' => ['short_text' => 'Test title']]);
        self::assertSame('test-title-1', $uid);

        $uidWithEmptyTarget = self::service()->generateUIDField(['contentTypeUID' => self::UID, 'field' => 'slug', 'data' => ['short_text' => '']]);
        self::assertSame('kitchensink', $uidWithEmptyTarget);
    }

    public function testIgnoresMaxLengthAttribute(): void
    {
        $uid = self::service()->generateUIDField(['contentTypeUID' => self::UID, 'field' => 'slug', 'data' => ['short_text' => 'Test with a 999 very long title']]);

        self::assertSame('test-with-a-999-very-long-title', $uid);
    }

    public function testFindsClosestMatch(): void
    {
        self::createWithSlug('my-test-model');
        self::createWithSlug('my-test-model-1');
        // it finds the quickest match possible
        self::createWithSlug('my-test-model-4');

        $uid = self::service()->findUniqueUID(['contentTypeUID' => self::UID, 'field' => 'slug', 'value' => 'my-test-model']);

        self::assertSame('my-test-model-2', $uid);
    }

    public function testFindUniqueUIDReturnsTheValueWhenNothingCollides(): void
    {
        self::createWithSlug('my-test-model-other');

        $uid = self::service()->findUniqueUID(['contentTypeUID' => self::UID, 'field' => 'slug', 'value' => 'my-test-model']);

        self::assertSame('my-test-model', $uid);
    }

    public function testCheckUIDAvailabilityCountsTheDataInDb(): void
    {
        self::assertTrue(self::service()->checkUIDAvailability(['contentTypeUID' => self::UID, 'field' => 'slug', 'value' => 'my-test-model']));

        self::createWithSlug('my-test-model');

        self::assertFalse(self::service()->checkUIDAvailability(['contentTypeUID' => self::UID, 'field' => 'slug', 'value' => 'my-test-model']));
    }
}
