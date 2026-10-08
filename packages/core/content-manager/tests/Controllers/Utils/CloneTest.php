<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers\Utils;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\Utils\CloneUtils;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/utils/__tests__/clone.test.ts. */
final class CloneTest extends TestCase
{
    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::addContentTypes($this->strapi, [
            'simple' => ['info' => ['displayName' => 'Simple'], 'attributes' => ['text' => ['type' => 'string']]],
            'simpleUnique' => ['attributes' => ['text' => ['type' => 'string', 'unique' => true]]],
            'component' => ['info' => ['displayName' => 'Fake component'], 'attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'simple']]],
            'componentUnique' => ['info' => ['displayName' => 'Unique Component'], 'attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'simpleUnique']]],
            'dynZone' => ['attributes' => ['dynZoneAttrName' => ['type' => 'dynamiczone', 'components' => ['simple', 'component']]]],
            'dynZoneUnique' => ['attributes' => ['dynZoneAttrName' => ['type' => 'dynamiczone', 'components' => ['simple', 'componentUnique']]]],
            'relations' => ['attributes' => [
                'one_way' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'simple'],
                'one_to_one' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'simple', 'private' => true, 'inversedBy' => 'one_to_one_kitchensink'],
                'one_to_many' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'simple', 'mappedBy' => 'many_to_one_kitchensink'],
                'many_to_one' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'simple', 'inversedBy' => 'one_to_many_kitchensinks'],
                'many_to_manys' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'simple', 'inversedBy' => 'many_to_many_kitchensinks'],
                'many_way' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'simple'],
                'morph_to_one' => ['type' => 'relation', 'relation' => 'morphToOne'],
                'morph_to_many' => ['type' => 'relation', 'relation' => 'morphToMany'],
            ]],
            'media' => ['attributes' => ['mediaAttrName' => ['type' => 'media']]],
        ]);
    }

    public function testModelWithoutUniqueFields(): void
    {
        self::assertCount(0, CloneUtils::getProhibitedCloningFields($this->strapi, 'simple'));
    }

    public function testModelWithUniqueFields(): void
    {
        self::assertSame([[['text'], 'unique']], CloneUtils::getProhibitedCloningFields($this->strapi, 'simpleUnique'));
    }

    public function testModelWithComponent(): void
    {
        self::assertCount(0, CloneUtils::getProhibitedCloningFields($this->strapi, 'component'));
    }

    public function testModelWithComponentAndUniqueFields(): void
    {
        self::assertSame([[['componentAttrName', 'text'], 'unique']], CloneUtils::getProhibitedCloningFields($this->strapi, 'componentUnique'));
    }

    public function testModelWithDynamicZone(): void
    {
        self::assertCount(0, CloneUtils::getProhibitedCloningFields($this->strapi, 'dynZone'));
    }

    public function testModelWithUniqueComponentInDynamicZone(): void
    {
        self::assertSame(
            [[['dynZoneAttrName', 'Unique Component', 'componentAttrName', 'text'], 'unique']],
            CloneUtils::getProhibitedCloningFields($this->strapi, 'dynZoneUnique'),
        );
    }

    public function testModelWithRelations(): void
    {
        self::assertSame(
            [[['one_to_one'], 'relation'], [['one_to_many'], 'relation']],
            CloneUtils::getProhibitedCloningFields($this->strapi, 'relations'),
        );
    }

    public function testModelWithMedia(): void
    {
        self::assertCount(0, CloneUtils::getProhibitedCloningFields($this->strapi, 'media'));
    }
}
