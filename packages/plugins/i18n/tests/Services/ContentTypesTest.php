<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\ContentTypes;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/** Port of server/src/services/__tests__/content-types.test.ts (`getValidLocale`: see LocalesTest, it needs the locales table). */
final class ContentTypesTest extends TestCase
{
    private Strapi $strapi;

    private ContentTypes $service;

    protected function setUp(): void
    {
        $this->strapi = I18nTestApp::create();
        $this->service = new ContentTypes($this->strapi);
    }

    public function testIsLocalizedContentTypeChecksForTheI18nOption(): void
    {
        self::assertFalse($this->service->isLocalizedContentType(['pluginOptions' => ['i18n' => ['localized' => false]]]));
        self::assertTrue($this->service->isLocalizedContentType(['pluginOptions' => ['i18n' => ['localized' => true]]]));
    }

    public function testIsLocalizedContentTypeDefaultsToFalse(): void
    {
        self::assertFalse($this->service->isLocalizedContentType([]));
        self::assertFalse($this->service->isLocalizedContentType(['pluginOptions' => []]));
        self::assertFalse($this->service->isLocalizedContentType(['pluginOptions' => ['i18n' => []]]));
    }

    public function testGetNonLocalizedAttributesUsesThePluginOptions(): void
    {
        self::assertSame(['stars', 'price'], $this->service->getNonLocalizedAttributes([
            'uid' => 'test-model',
            'attributes' => [
                'title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => true]]],
                'stars' => ['type' => 'integer'],
                'price' => ['type' => 'integer'],
            ],
        ]));
    }

    public function testRelationsAreAlwaysLocalized(): void
    {
        self::assertSame(['stars', 'price'], $this->service->getNonLocalizedAttributes([
            'uid' => 'test-model',
            'attributes' => [
                'title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => true]]],
                'stars' => ['type' => 'integer'],
                'price' => ['type' => 'integer'],
                'relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'user'],
                'secondRelation' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'user'],
            ],
        ]));
    }

    public function testLocaleLocalizationsAndPublishedAtAreLocalized(): void
    {
        self::assertSame(['stars', 'price'], $this->service->getNonLocalizedAttributes([
            'uid' => 'test-model',
            'attributes' => [
                'title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => true]]],
                'stars' => ['type' => 'integer'],
                'price' => ['type' => 'integer'],
                'locale' => ['type' => 'string', 'visible' => false],
                'localizations' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'test-model', 'visible' => false],
                'publishedAt' => ['type' => 'datetime', 'visible' => false],
            ],
        ]));
    }

    public function testUidIsAlwaysLocalized(): void
    {
        self::assertSame(['price'], $this->service->getNonLocalizedAttributes([
            'attributes' => ['price' => ['type' => 'integer'], 'slug' => ['type' => 'uid']],
        ]));
    }

    public function testCopyDoesNotCopyLocaleLocalizationsAndPublishedAt(): void
    {
        $model = ['attributes' => [
            'title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => true]]],
            'price' => ['type' => 'integer'],
            'relation' => ['type' => 'relation'],
            'description' => ['type' => 'string'],
            'locale' => ['type' => 'string', 'visible' => false],
            'localizations' => ['collection' => 'test-model', 'visible' => false],
            'publishedAt' => ['type' => 'datetime', 'visible' => false],
        ]];
        $input = [
            'id' => 1, 'title' => 'My custom title', 'price' => 25, 'relation' => 1, 'description' => 'My super description',
            'locale' => 'en', 'localizations' => [1, 2, 3], 'publishedAt' => '2021-03-18T09:47:37.557Z',
        ];

        self::assertSame(['price' => 25, 'description' => 'My super description'], $this->service->copyNonLocalizedAttributes($model, $input));
    }

    public function testCopyPicksOnlyNonLocalizedAttributes(): void
    {
        $model = ['attributes' => [
            'title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => true]]],
            'price' => ['type' => 'integer'],
            'relation' => ['type' => 'relation'],
            'description' => ['type' => 'string'],
        ]];
        $input = ['id' => 1, 'title' => 'My custom title', 'price' => 25, 'relation' => 1, 'description' => 'My super description'];

        self::assertSame(['price' => 25, 'description' => 'My super description'], $this->service->copyNonLocalizedAttributes($model, $input));
    }

    public function testCopyRemovesIds(): void
    {
        I18nTestApp::addComponents($this->strapi, ['compo' => ['attributes' => ['name' => ['type' => 'string']]]]);
        $model = ['attributes' => [
            'title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => true]]],
            'price' => ['type' => 'integer'],
            'relation' => ['type' => 'relation'],
            'component' => ['type' => 'component', 'component' => 'compo'],
        ]];
        $input = ['id' => 1, 'title' => 'My custom title', 'price' => 25, 'relation' => 1, 'component' => ['id' => 2, 'name' => 'Hello']];

        self::assertEquals(['price' => 25, 'component' => ['name' => 'Hello']], $this->service->copyNonLocalizedAttributes($model, $input));
    }

    public function testFillNonLocalizedAttributes(): void
    {
        $localized = ['pluginOptions' => ['i18n' => ['localized' => true]]];
        I18nTestApp::addContentTypes($this->strapi, ['model' => ['attributes' => [
            'a' => [], 'b' => [], 'c' => [], 'd' => [], 'e' => [],
            'la' => $localized, 'lb' => $localized, 'lc' => $localized, 'ld' => $localized, 'le' => $localized,
        ]]]);

        $entry = ['a' => 'a', 'b' => null, 'c' => null, 'd' => 1, 'e' => [], 'la' => 'a', 'lb' => null, 'lc' => null, 'ld' => 1, 'le' => []];
        $relatedEntry = ['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e', 'la' => 'la', 'lb' => 'lb', 'lc' => 'lc', 'ld' => 'ld', 'le' => 'le'];

        $this->service->fillNonLocalizedAttributes($entry, $relatedEntry, ['model' => 'model']);

        self::assertSame(['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 1, 'e' => [], 'la' => 'a', 'lb' => null, 'lc' => null, 'ld' => 1, 'le' => []], $entry);
    }

    public function testGetNestedPopulatePopulatesComponentDzAndMediaButNotRelations(): void
    {
        $nonLocalized = ['i18n' => ['localized' => false]];
        I18nTestApp::addContentTypes($this->strapi, ['api::country.country' => ['attributes' => [
            'name' => ['type' => 'string'],
            'nonLocalizedName' => ['type' => 'string', 'pluginOptions' => $nonLocalized],
            'comp' => ['type' => 'component', 'repeatable' => false, 'component' => 'basic.mycompo', 'pluginOptions' => $nonLocalized],
            'dz' => ['type' => 'dynamiczone', 'components' => ['basic.mycompo', 'default.mydz'], 'pluginOptions' => $nonLocalized],
            'myrelation' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'api::category.category', 'inversedBy' => 'addresses'],
        ]]]);
        // upstream's fixtures have no modelType: these are read as content types (no relation populate)
        I18nTestApp::addContentTypes($this->strapi, [
            'basic.mycompo' => ['attributes' => [
                'title' => ['type' => 'string'],
                'image' => ['allowedTypes' => ['images', 'files', 'videos'], 'type' => 'media', 'multiple' => false],
            ]],
            'default.mydz' => ['attributes' => ['name' => ['type' => 'string'], 'picture' => ['type' => 'media']]],
        ]);

        self::assertSame(['comp', 'dz', 'comp.image', 'dz.image', 'dz.picture'], $this->service->getNestedPopulateOfNonLocalizedAttributes('api::country.country'));
    }
}
