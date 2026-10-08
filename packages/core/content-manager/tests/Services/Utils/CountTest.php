<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Count;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/utils/__tests__/count.test.ts. */
final class CountTest extends TestCase
{
    private Strapi $strapi;

    private const array RELATIONS = [['id' => 2, 'name' => 'rel1'], ['id' => 7, 'name' => 'rel2']];

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::addContentTypes($this->strapi, [
            'component' => ['attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'relationMTM']]],
            'repeatableComponent' => ['attributes' => ['repeatableComponentAttrName' => ['type' => 'component', 'repeatable' => true, 'component' => 'relationMTM']]],
            'dynZone' => ['attributes' => ['dynZoneAttrName' => ['type' => 'dynamiczone', 'components' => ['component']]]],
            'relationMTM' => ['attributes' => ['relationAttrName' => ['type' => 'relation', 'relation' => 'oneToMany']]],
            'relationOTO' => ['attributes' => ['relationAttrName' => ['type' => 'relation', 'relation' => 'oneToOne']]],
            'media' => ['attributes' => ['mediaAttrName' => ['type' => 'media']]],
        ]);
    }

    public function testRelationFieldsWithManyToMany(): void
    {
        $count = Count::getDeepRelationsCount($this->strapi, ['id' => 1, 'relationAttrName' => self::RELATIONS], 'relationMTM');

        self::assertSame(['id' => 1, 'relationAttrName' => ['count' => 2]], $count);
    }

    public function testRelationFieldsWithOneToOne(): void
    {
        $count = Count::getDeepRelationsCount($this->strapi, ['id' => 1, 'relationAttrName' => ['id' => 2, 'name' => 'rel1']], 'relationOTO');

        self::assertSame(['id' => 1, 'relationAttrName' => ['count' => 1]], $count);
    }

    public function testMediaFieldsWithMedia(): void
    {
        $mediaEntity = ['id' => 1, 'mediaAttrName' => ['id' => 1, 'name' => 'img1']];

        self::assertSame($mediaEntity, Count::getDeepRelationsCount($this->strapi, $mediaEntity, 'media'));
    }

    public function testComponentFieldsWithComponent(): void
    {
        $count = Count::getDeepRelationsCount($this->strapi, ['id' => 1, 'componentAttrName' => ['relationAttrName' => self::RELATIONS]], 'component');

        self::assertSame(['id' => 1, 'componentAttrName' => ['relationAttrName' => ['count' => 2]]], $count);
    }

    public function testComponentFieldsWithEmptyComponent(): void
    {
        $count = Count::getDeepRelationsCount($this->strapi, ['id' => 1, 'componentAttrName' => null], 'component');

        self::assertSame(['id' => 1, 'componentAttrName' => null], $count);
    }

    public function testComponentFieldsWithRepeatableComponent(): void
    {
        $count = Count::getDeepRelationsCount($this->strapi, [
            'id' => 1,
            'repeatableComponentAttrName' => [['relationAttrName' => self::RELATIONS]],
        ], 'repeatableComponent');

        self::assertSame(['id' => 1, 'repeatableComponentAttrName' => [['relationAttrName' => ['count' => 2]]]], $count);
    }

    public function testDynamicZoneFieldsWithDynamicZone(): void
    {
        $count = Count::getDeepRelationsCount($this->strapi, [
            'id' => 1,
            'dynZoneAttrName' => [
                ['__component' => 'component', 'componentAttrName' => ['relationAttrName' => self::RELATIONS]],
            ],
        ], 'dynZone');

        self::assertSame([
            'id' => 1,
            'dynZoneAttrName' => [
                ['__component' => 'component', 'componentAttrName' => ['relationAttrName' => ['count' => 2]]],
            ],
        ], $count);
    }
}
