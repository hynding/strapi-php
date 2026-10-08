<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/utils/__tests__/populate.test.ts. */
final class PopulateTest extends TestCase
{
    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::addContentTypes($this->strapi, [
            'empty' => ['attributes' => []],
            'component' => ['attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'empty']]],
            'dynZone' => ['attributes' => ['dynZoneAttrName' => ['type' => 'dynamiczone', 'components' => ['empty', 'component']]]],
            'relationOTM' => ['attributes' => ['relationAttrName' => ['type' => 'relation', 'relation' => 'oneToMany']]],
            'relationOTO' => ['attributes' => ['relationAttrName' => ['type' => 'relation', 'relation' => 'oneToOne']]],
            'media' => ['attributes' => ['mediaAttrName' => ['type' => 'media']]],
            'withLocalizations' => [
                'pluginOptions' => ['i18n' => ['localized' => true]],
                'attributes' => [
                    'title' => ['type' => 'string'],
                    'localizations' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::article.article', 'visible' => false],
                ],
            ],
        ]);
    }

    public function testWithEmptyModel(): void
    {
        self::assertSame([], Populate::getDeepPopulate($this->strapi, 'empty'));
    }

    public function testWithComponentModel(): void
    {
        self::assertSame(['componentAttrName' => ['populate' => []]], Populate::getDeepPopulate($this->strapi, 'component'));
    }

    public function testWithDynamicZoneModel(): void
    {
        self::assertEquals([
            'dynZoneAttrName' => [
                'on' => [
                    'component' => ['populate' => ['componentAttrName' => ['populate' => []]]],
                    'empty' => ['populate' => []],
                ],
            ],
        ], Populate::getDeepPopulate($this->strapi, 'dynZone'));
    }

    public function testWithRelationModelOneToMany(): void
    {
        self::assertSame(['relationAttrName' => true], Populate::getDeepPopulate($this->strapi, 'relationOTM'));
    }

    public function testWithRelationModelOneToManyWithCountMany(): void
    {
        self::assertSame(['relationAttrName' => ['count' => true]], Populate::getDeepPopulate($this->strapi, 'relationOTM', ['countMany' => true]));
    }

    public function testWithRelationModelOneToOne(): void
    {
        self::assertSame(['relationAttrName' => true], Populate::getDeepPopulate($this->strapi, 'relationOTO'));
    }

    public function testWithRelationModelOneToOneWithCountOne(): void
    {
        self::assertSame(['relationAttrName' => ['count' => true]], Populate::getDeepPopulate($this->strapi, 'relationOTO', ['countOne' => true]));
    }

    public function testWithMediaModel(): void
    {
        self::assertSame(['mediaAttrName' => ['populate' => ['folder' => true]]], Populate::getDeepPopulate($this->strapi, 'media'));
    }

    public function testInitialPopulateDefaultsToValidationPopulateForLocalizations(): void
    {
        $result = Populate::getDeepPopulate($this->strapi, 'withLocalizations');

        // Default behavior: localizations gets validation populate (populated via getPopulateForValidation)
        self::assertArrayHasKey('localizations', $result);
        self::assertArrayHasKey('populate', $result['localizations']);
    }

    public function testInitialPopulateOverridesLocalizationsWithMinimalFields(): void
    {
        $result = Populate::getDeepPopulate($this->strapi, 'withLocalizations', [
            'initialPopulate' => ['localizations' => ['fields' => ['locale', 'documentId', 'publishedAt', 'updatedAt']]],
        ]);

        self::assertSame(['localizations' => ['fields' => ['locale', 'documentId', 'publishedAt', 'updatedAt']]], $result);
    }

    public function testInitialPopulateWithFalseSuppressesLocalizationsPopulate(): void
    {
        $result = Populate::getDeepPopulate($this->strapi, 'withLocalizations', [
            'initialPopulate' => ['localizations' => false],
        ]);

        self::assertSame(['localizations' => false], $result);
    }

    public function testInitialPopulateOverrideWorksForAnyRelationAttribute(): void
    {
        $result = Populate::getDeepPopulate($this->strapi, 'relationOTM', [
            'initialPopulate' => ['relationAttrName' => ['fields' => ['id', 'name']]],
        ]);

        self::assertSame(['relationAttrName' => ['fields' => ['id', 'name']]], $result);
    }
}
