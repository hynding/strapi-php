<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\Relations;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of server/src/controllers/__tests__/relations-find-available.test.ts (and of the
 * `describe.skip`ped relations.test.ts mainField cases).
 *
 * Upstream asserts the where clause given to a mocked query builder; here findAvailable runs on a
 * booted app (getstarted's kitchensink → tag relations, both with draft & publish) and the
 * excluded relations are asserted on the result.
 */
final class RelationsFindAvailableTest extends TestCase
{
    private static Strapi $strapi;

    /** @var array<string, mixed> */
    private static array $user;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
        self::$user = StubStrapi::createSuperAdmin(self::$strapi, 'relations@strapi.io');
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    /** @param array<string, mixed> $query */
    private static function ctx(array $query): Context
    {
        $ability = StubStrapi::userAbility(self::$strapi, self::$user);

        return StubStrapi::ctx(
            query: $query,
            params: ['model' => 'api::kitchensink.kitchensink', 'targetField' => 'many_to_many_tags'],
            state: ['user' => self::$user, 'userAbility' => $ability],
        );
    }

    /** @return list<string> */
    private static function names(Context $ctx): array
    {
        $body = $ctx->body();
        self::assertIsArray($body, (string) json_encode($body));

        return array_values(array_map(static fn (array $r): string => (string) ($r['name'] ?? $r['documentId']), $body['results']));
    }

    public function testDraftAndPublishedExclusionOnlyMatchTheSourceRowOfTheRequestedStatus(): void
    {
        $documents = self::$strapi->documents('api::tag.tag');
        $a = $documents->create(['data' => ['name' => 'tag-a']]);
        $b = $documents->create(['data' => ['name' => 'tag-b']]);
        $documents->publish(['documentId' => $a['documentId']]);
        $documents->publish(['documentId' => $b['documentId']]);

        // the published source row relates to tag-a, the draft row to tag-b (issue #23743)
        $sink = self::$strapi->documents('api::kitchensink.kitchensink')->create(['data' => ['short_text' => 'sink', 'many_to_many_tags' => [$a['documentId']]]]);
        self::$strapi->documents('api::kitchensink.kitchensink')->publish(['documentId' => $sink['documentId']]);
        self::$strapi->documents('api::kitchensink.kitchensink')->update(['documentId' => $sink['documentId'], 'data' => ['many_to_many_tags' => [$b['documentId']]]]);

        $relations = new Relations(self::$strapi);

        $draftCtx = self::ctx(['id' => $sink['documentId'], 'status' => 'draft']);
        $relations->findAvailable($draftCtx);
        $draft = self::names($draftCtx);
        self::assertNotContains('tag-b', $draft);
        self::assertContains('tag-a', $draft);

        $publishedCtx = self::ctx(['id' => $sink['documentId'], 'status' => 'published']);
        $relations->findAvailable($publishedCtx);
        $published = self::names($publishedCtx);
        self::assertNotContains('tag-a', $published);
        self::assertContains('tag-b', $published);
    }

    public function testSearchesOnTheMainField(): void
    {
        self::$strapi->documents('api::tag.tag')->create(['data' => ['name' => 'searchable-foobar']]);

        $ctx = self::ctx(['_q' => 'foobar']);
        (new Relations(self::$strapi))->findAvailable($ctx);

        self::assertSame(['searchable-foobar'], self::names($ctx));
    }
}
