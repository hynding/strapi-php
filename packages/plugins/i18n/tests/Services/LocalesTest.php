<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\ContentTypes;
use Strapi\Plugin\I18n\Services\Locales;
use Strapi\Plugin\I18n\Tests\I18nTestApp;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/__tests__/locales.test.ts (and of content-types.test.ts'
 * `getValidLocale`) against a booted app: the plugin's bootstrap created the default `en` locale.
 */
final class LocalesTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<array{string, mixed}> */
    private array $events = [];

    private ?\Closure $unsubscribe = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = I18nTestApp::boot();
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

    private static function service(): Locales
    {
        $service = self::strapi()->service('plugin::i18n.locales');
        assert($service instanceof Locales);

        return $service;
    }

    protected function setUp(): void
    {
        $this->events = [];
        $unsubscribe = self::strapi()->eventHub()->subscribe(function (string $event, mixed ...$args): void {
            if (str_starts_with($event, 'locale.')) {
                $this->events[] = [$event, $args[0] ?? null];
            }
        });
        $this->unsubscribe = \Closure::fromCallable($unsubscribe);
    }

    protected function tearDown(): void
    {
        if ($this->unsubscribe !== null) {
            ($this->unsubscribe)();
        }
        // back to the bootstrap state: en only, en as default
        foreach (self::service()->find() as $locale) {
            if ($locale['code'] !== 'en') {
                self::strapi()->db()->query('plugin::i18n.locale')->delete(['where' => ['id' => $locale['id']]]);
            }
        }
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'i18n', 'key' => 'default_locale', 'value' => 'en']);
    }

    public function testInitDefaultLocaleCreatedEnglishAsTheDefault(): void
    {
        $locales = self::service()->find();
        self::assertCount(1, $locales);
        self::assertSame('en', $locales[0]['code']);
        self::assertSame('English (en)', $locales[0]['name']);
        self::assertSame('en', self::service()->getDefaultLocale());
    }

    public function testInitDefaultLocaleDoesNothingIfOneAlreadyExists(): void
    {
        self::service()->initDefaultLocale();
        self::assertSame(1, self::service()->count());
        self::assertSame([], $this->events);
    }

    public function testSetIsDefault(): void
    {
        self::assertSame(['code' => 'fr', 'isDefault' => false], self::service()->setIsDefault(['code' => 'fr']));
        self::assertSame(['code' => 'en', 'isDefault' => true], self::service()->setIsDefault(['code' => 'en']));
        self::assertSame([['code' => 'en', 'isDefault' => true], ['code' => 'fr', 'isDefault' => false]], self::service()->setIsDefault([['code' => 'en'], ['code' => 'fr']]));
        self::assertNull(self::service()->setIsDefault(null));
    }

    public function testCrudAndAuditEvents(): void
    {
        $fr = self::service()->create(['name' => 'French', 'code' => 'fr']);
        self::assertSame('fr', $fr['code']);
        self::assertSame(['locale.create', ['localeId' => $fr['id'], 'name' => 'French', 'code' => 'fr', 'isDefault' => false]], $this->events[0]);

        self::assertSame($fr['id'], self::service()->findByCode('fr')['id'] ?? null);
        self::assertSame('fr', self::service()->findById($fr['id'])['code'] ?? null);
        self::assertSame(2, self::service()->count());
        self::assertCount(1, self::service()->find(['code' => 'fr']));

        $updated = self::service()->update(['id' => $fr['id']], ['name' => 'Français']);
        self::assertSame('Français', $updated['name'] ?? null);
        self::assertSame(['locale.update', [
            'localeId' => $fr['id'], 'name' => 'Français', 'code' => 'fr',
            'changes' => ['name' => ['before' => 'French', 'after' => 'Français']],
        ]], $this->events[1]);

        // no emit when the name does not change, or when nothing matched
        self::service()->update(['id' => $fr['id']], ['name' => 'Français']);
        self::assertNull(self::service()->update(['id' => 999999], ['name' => 'X']));
        self::assertCount(2, $this->events);

        $deleted = self::service()->delete(['id' => $fr['id']]);
        self::assertSame('fr', $deleted['code'] ?? null);
        self::assertSame(['locale.delete', ['localeId' => $fr['id'], 'name' => 'Français', 'code' => 'fr']], $this->events[2]);

        self::assertNull(self::service()->delete(['id' => $fr['id']]));
        self::assertCount(3, $this->events);
    }

    public function testCreateRecordsTheLocaleAsDefaultWhenTheCallerSaysSo(): void
    {
        self::service()->create(['name' => 'German', 'code' => 'de'], ['isDefault' => true]);
        self::assertTrue($this->events[0][1]['isDefault']);
    }

    public function testSetDefaultLocale(): void
    {
        $en = self::service()->findByCode('en');
        $frCa = self::service()->create(['name' => null, 'code' => 'fr-CA']);
        $this->events = [];

        self::service()->setDefaultLocale(['code' => 'fr-CA']);

        self::assertSame('fr-CA', self::service()->getDefaultLocale());
        self::assertSame([['locale.default.update', [
            'localeId' => $frCa['id'],
            'name' => null,
            'code' => 'fr-CA',
            'changes' => ['defaultLocale' => ['before' => ['id' => $en['id'] ?? null, 'code' => 'en'], 'after' => ['id' => $frCa['id'], 'code' => 'fr-CA']]],
        ]]], $this->events);
    }

    public function testSetDefaultLocaleKeepsThePreviousCodeWhenItsLocaleNoLongerExists(): void
    {
        $frCa = self::service()->create(['name' => null, 'code' => 'fr-CA']);
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'i18n', 'key' => 'default_locale', 'value' => 'de']);
        $this->events = [];

        self::service()->setDefaultLocale(['code' => 'fr-CA']);

        self::assertSame(['before' => ['id' => null, 'code' => 'de'], 'after' => ['id' => $frCa['id'], 'code' => 'fr-CA']], $this->events[0][1]['changes']['defaultLocale']);
    }

    public function testSetDefaultLocaleDoesNotEmitWhenUnchanged(): void
    {
        self::service()->setDefaultLocale(['code' => 'en']);
        self::assertSame('en', self::service()->getDefaultLocale());
        self::assertSame([], $this->events);
    }

    public function testGetValidLocale(): void
    {
        $contentTypes = self::strapi()->service('plugin::i18n.content-types');
        assert($contentTypes instanceof ContentTypes);

        self::assertSame('en', $contentTypes->getValidLocale(null));
        self::assertSame('en', $contentTypes->getValidLocale('en'));

        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Locale not found');
        $contentTypes->getValidLocale('zz');
    }

    public function testDeleteRemovesTheEntriesOfLocalizedContentTypesInThatLocale(): void
    {
        self::service()->create(['name' => 'French', 'code' => 'fr']);
        $documents = self::strapi()->documents('api::country.country');
        $doc = $documents->create(['data' => ['name' => 'Belgique', 'code' => 'be'], 'locale' => 'fr']);
        self::assertSame(1, self::strapi()->db()->query('api::country.country')->count(['where' => ['locale' => 'fr']]));

        self::service()->delete(['id' => self::service()->findByCode('fr')['id'] ?? null]);

        self::assertSame(0, self::strapi()->db()->query('api::country.country')->count(['where' => ['locale' => 'fr', 'documentId' => $doc['documentId']]]));
    }
}
