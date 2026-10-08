<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\Localizations;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/**
 * Port of server/src/services/__tests__/localizations.test.ts, on a booted app:
 * `api::country.country` (no draft & publish) has localized `name`/`code` and a non-localized
 * `visible`; the plugin's document-service middleware calls `syncNonLocalizedAttributes`.
 */
final class LocalizationsTest extends TestCase
{
    private const string UID = 'api::country.country';

    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = I18nTestApp::boot();
        self::$strapi->service('plugin::i18n.locales')->create(['name' => 'French', 'code' => 'fr']);
        self::$strapi->service('plugin::i18n.locales')->create(['name' => 'Italian', 'code' => 'it']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private static function service(): Localizations
    {
        $service = self::strapi()->service('plugin::i18n.localizations');
        assert($service instanceof Localizations);

        return $service;
    }

    /** @return array<string, mixed> */
    private static function row(string $documentId, string $locale): array
    {
        return self::strapi()->db()->query(self::UID)->findOne(['where' => ['documentId' => $documentId, 'locale' => $locale]]) ?? [];
    }

    public function testDoesNothingIfNoLocalizationsSet(): void
    {
        $doc = self::strapi()->documents(self::UID)->create(['data' => ['name' => 'Alone', 'code' => 'al', 'visible' => true], 'locale' => 'en']);

        self::service()->syncNonLocalizedAttributes(self::row($doc['documentId'], 'en'), self::strapi()->contentType(self::UID));

        self::assertSame(1, self::strapi()->db()->query(self::UID)->count(['where' => ['documentId' => $doc['documentId']]]));
    }

    public function testDoesNotUpdateIfAllTheFieldsAreLocalized(): void
    {
        $doc = self::strapi()->documents(self::UID)->create(['data' => ['name' => 'Belgium', 'code' => 'be', 'visible' => true], 'locale' => 'en']);
        self::strapi()->documents(self::UID)->update(['documentId' => $doc['documentId'], 'locale' => 'fr', 'data' => ['name' => 'Belgique', 'code' => 'bf']]);

        $entry = self::row($doc['documentId'], 'en');
        unset($entry['visible']);
        self::service()->syncNonLocalizedAttributes([...$entry, 'name' => 'Changed'], self::strapi()->contentType(self::UID));

        self::assertSame('Belgique', self::row($doc['documentId'], 'fr')['name']);
    }

    public function testSyncsNonLocalizedFieldsToTheOtherLocalesButNotTheCurrentOne(): void
    {
        $doc = self::strapi()->documents(self::UID)->create(['data' => ['name' => 'Spain', 'code' => 'es', 'visible' => true], 'locale' => 'en']);
        self::strapi()->documents(self::UID)->update(['documentId' => $doc['documentId'], 'locale' => 'fr', 'data' => ['name' => 'Espagne', 'code' => 'ef']]);
        self::strapi()->documents(self::UID)->update(['documentId' => $doc['documentId'], 'locale' => 'it', 'data' => ['name' => 'Spagna', 'code' => 'ei']]);

        // the new localizations were filled from the existing entry
        self::assertTrue((bool) self::row($doc['documentId'], 'fr')['visible']);

        self::strapi()->documents(self::UID)->update(['documentId' => $doc['documentId'], 'locale' => 'en', 'data' => ['visible' => false]]);

        self::assertFalse((bool) self::row($doc['documentId'], 'fr')['visible']);
        self::assertFalse((bool) self::row($doc['documentId'], 'it')['visible']);
        self::assertSame('Espagne', self::row($doc['documentId'], 'fr')['name']);
        self::assertSame('Spain', self::row($doc['documentId'], 'en')['name']);
    }

    public function testNormalizeMediaIds(): void
    {
        $schema = I18nTestApp::schema('api::x.x', ['attributes' => [
            'cover' => ['type' => 'media', 'multiple' => false],
            'gallery' => ['type' => 'media', 'multiple' => true],
            'title' => ['type' => 'string'],
        ]]);

        self::assertSame(
            ['cover' => 3, 'gallery' => [4, 5], 'title' => 'x'],
            self::service()->normalizeMediaIds($schema, ['cover' => ['id' => 3, 'url' => '/a'], 'gallery' => [['id' => 4], 5], 'title' => 'x']),
        );
    }
}
