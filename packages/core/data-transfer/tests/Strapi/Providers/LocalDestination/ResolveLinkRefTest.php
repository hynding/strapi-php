<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Strapi\Providers\LocalDestination;

use Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore\ResolveLinkRef;
use Strapi\DataTransfer\Tests\BootedAppTestCase;

/**
 * Port of src/strapi/providers/local-destination/__tests__/resolve-link-ref.test.ts, with the
 * fixture app's metadata (articles → categories through a join table; i18n `localizations` of the
 * localized api::page.page, a `document_id` join column) instead of a mocked `strapi.db.metadata`.
 */
final class ResolveLinkRefTest extends BootedAppTestCase
{
    /** @var list<array{string, int}> */
    private array $calls = [];

    private function mapID(): \Closure
    {
        return function (string $uid, int $id): ?int {
            $this->calls[] = [$uid, $id];
            $mappings = ['api::article.article' => [1 => 101, 2 => 102], 'api::page.page' => [1 => 201]];

            return $mappings[$uid][$id] ?? null;
        };
    }

    public function testMapsNumericRefsThroughTheEntitiesMapper(): void
    {
        $link = [
            'kind' => 'relation.basic',
            'relation' => 'manyToOne',
            'left' => ['type' => 'api::article.article', 'ref' => 1, 'field' => 'category'],
            'right' => ['type' => 'api::category.category', 'ref' => 2],
        ];

        self::assertSame(101, ResolveLinkRef::resolveLinkRef(self::strapi(), $link, 'left', $this->mapID()));
        self::assertNull(ResolveLinkRef::resolveLinkRef(self::strapi(), $link, 'right', $this->mapID()));
        self::assertSame([['api::article.article', 1], ['api::category.category', 2]], $this->calls);
    }

    public function testPassesDocumentIdJoinColumnTargetsThroughWithoutIdMapping(): void
    {
        $documentId = 'kq4sntx4a0kymmdpvwvyblb9';
        $link = [
            'kind' => 'relation.circular',
            'relation' => 'oneToMany',
            'left' => ['type' => 'api::page.page', 'ref' => 1, 'field' => 'localizations'],
            'right' => ['type' => 'api::page.page', 'ref' => $documentId],
        ];

        self::assertTrue(ResolveLinkRef::isDocumentIdJoinColumnTarget(self::strapi(), $link, 'right'));
        self::assertFalse(ResolveLinkRef::isDocumentIdJoinColumnTarget(self::strapi(), $link, 'left'));
        self::assertSame(201, ResolveLinkRef::resolveLinkRef(self::strapi(), $link, 'left', $this->mapID()));
        self::assertSame($documentId, ResolveLinkRef::resolveLinkRef(self::strapi(), $link, 'right', $this->mapID()));
        self::assertCount(1, $this->calls);
    }
}
