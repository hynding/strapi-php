<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';
require_once __DIR__ . '/../Mock.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\ContentStructure;
use Strapi\ContentManager\Tests\Mock;
use Strapi\ContentManager\Tests\StubStrapi;

/** Port of server/src/services/__tests__/content-structure.test.ts. */
final class ContentStructureTest extends TestCase
{
    private Mock $coreService;

    /**
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private static function group(string $id, string $name, array $children): array
    {
        return ['type' => 'group', 'id' => $id, 'name' => $name, 'children' => $children];
    }

    /** @return array{type: 'contentType', uid: string} */
    private static function ct(string $uid): array
    {
        return ['type' => 'contentType', 'uid' => $uid];
    }

    /** @param array<string, mixed> $resolved */
    private function makeService(array $resolved, mixed $cleaned = ['version' => 1]): ContentStructure
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'api::article.article' => [],
            'api::page.page' => [],
            'api::secret.secret' => ['pluginOptions' => ['content-manager' => ['visible' => false]]],
            'api::draft.draft' => ['pluginOptions' => ['content-type-builder' => ['visible' => false]]],
            'admin::user' => [],
            'admin::role' => [],
            'strapi::webhook' => [],
            'strapi::core-store' => [],
        ]);
        $this->coreService = new Mock(['getCleanedFile' => $cleaned, 'resolve' => $resolved]);
        $strapi->set('content-structure', $this->coreService);

        return new ContentStructure($strapi);
    }

    public function testReturnsNullWhenThereIsNoFolderFile(): void
    {
        $service = $this->makeService(['collectionTypes' => [], 'singleTypes' => []], null);

        self::assertNull($service->getContentStructure());
        self::assertSame(0, $this->coreService->called('resolve'));
    }

    public function testDropsInternalHiddenAndUnknownContentTypesButKeepsVisibleOnes(): void
    {
        $service = $this->makeService([
            'collectionTypes' => [
                self::group('grp_root', 'Root', [
                    self::ct('api::article.article'), // visible → keep
                    self::ct('api::secret.secret'), // hidden → drop
                    self::ct('admin::user'), // internal → drop
                    self::ct('strapi::webhook'), // internal → drop
                    self::ct('api::ghost.ghost'), // unknown → drop
                    self::group('grp_nested', 'Nested', [
                        self::ct('admin::role'), // internal → drop
                        self::ct('api::page.page'), // visible → keep
                    ]),
                    self::group('grp_empty', 'Empty', [
                        self::ct('strapi::core-store'), // internal → drop, group kept but empty
                    ]),
                ]),
            ],
            'singleTypes' => [],
        ]);

        self::assertSame([
            'collectionTypes' => [
                self::group('grp_root', 'Root', [
                    self::ct('api::article.article'),
                    self::group('grp_nested', 'Nested', [self::ct('api::page.page')]),
                    self::group('grp_empty', 'Empty', []),
                ]),
            ],
            'singleTypes' => [],
        ], $service->getContentStructure());
    }

    public function testPrunesBothSectionsIndependently(): void
    {
        $service = $this->makeService([
            'collectionTypes' => [self::group('grp_c', 'Collections', [self::ct('api::article.article'), self::ct('admin::user')])],
            'singleTypes' => [self::group('grp_s', 'Singles', [self::ct('strapi::webhook'), self::ct('api::page.page')])],
        ]);

        self::assertSame([
            'collectionTypes' => [self::group('grp_c', 'Collections', [self::ct('api::article.article')])],
            'singleTypes' => [self::group('grp_s', 'Singles', [self::ct('api::page.page')])],
        ], $service->getContentStructure());
    }

    public function testKeepsATypeHiddenInTheBuilderButVisibleInTheContentManager(): void
    {
        $resolved = [
            'collectionTypes' => [self::group('grp_root', 'Root', [self::ct('api::article.article'), self::ct('api::draft.draft')])],
            'singleTypes' => [],
        ];

        self::assertSame($resolved, $this->makeService($resolved)->getContentStructure());
    }
}
