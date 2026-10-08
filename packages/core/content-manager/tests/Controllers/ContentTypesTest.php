<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers;

require_once __DIR__ . '/../StubStrapi.php';
require_once __DIR__ . '/../Mock.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\ContentTypes;
use Strapi\ContentManager\Tests\Mock;
use Strapi\ContentManager\Tests\StubStrapi;

/** Port of server/src/controllers/__tests__/content-types.test.ts. */
final class ContentTypesTest extends TestCase
{
    public function testFindContentTypesSettings(): void
    {
        $contentTypeUid = 'test-content-type';
        $fakeConfig = [
            'metadatas' => [],
            'layouts' => [],
            'settings' => [
                'bulkable' => true,
                'filterable' => true,
                'searchable' => true,
                'pageSize' => 10,
                'mainField' => 'username',
                'defaultSortBy' => 'username',
                'defaultSortOrder' => 'ASC',
            ],
        ];

        $strapi = StubStrapi::create();
        StubStrapi::setService($strapi, 'content-types', new Mock([
            'findAllContentTypes' => [['uid' => $contentTypeUid]],
            'findConfiguration' => ['uid' => $contentTypeUid, ...$fakeConfig],
        ]));

        $ctx = StubStrapi::ctx();
        (new ContentTypes($strapi))->findContentTypesSettings($ctx);

        self::assertSame(['data' => [['uid' => $contentTypeUid, 'settings' => $fakeConfig['settings']]]], $ctx->body());
    }

    public function testFindContentTypesRejectsAnInvalidKind(): void
    {
        $strapi = StubStrapi::create();
        $ctx = StubStrapi::ctx(query: ['kind' => 'notAKind']);

        (new ContentTypes($strapi))->findContentTypes($ctx);

        self::assertSame(400, $ctx->status());
        $body = $ctx->body();
        self::assertIsArray($body);
        self::assertSame('ValidationError', $body['error']['name']);
    }
}
