<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Controllers;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Controllers\Locales;
use Strapi\Plugin\I18n\Tests\I18nTestApp;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/__tests__/locales.test.ts on a booted app (`en` is the default locale). */
final class LocalesTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var array<string, mixed> */
    private static array $user;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = I18nTestApp::boot();
        self::$user = I18nTestApp::createSuperAdmin(self::$strapi, 'i18n-locales@strapi.io');
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

    private static function controller(): Locales
    {
        return new Locales(self::strapi());
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $params */
    private static function ctx(string $method, ?array $body = null, array $params = []): \Strapi\Core\Services\Server\Context
    {
        return I18nTestApp::ctx($method, '/i18n/locales', $body, [], $params, ['user' => self::$user]);
    }

    protected function tearDown(): void
    {
        foreach (self::strapi()->db()->query('plugin::i18n.locale')->findMany() as $locale) {
            if ($locale['code'] !== 'en') {
                self::strapi()->db()->query('plugin::i18n.locale')->delete(['where' => ['id' => $locale['id']]]);
            }
        }
        self::strapi()->store()->set(['type' => 'plugin', 'name' => 'i18n', 'key' => 'default_locale', 'value' => 'en']);
    }

    public function testCanGetLocales(): void
    {
        $ctx = self::ctx('GET');
        self::controller()->listLocales($ctx);

        $body = $ctx->body();
        self::assertCount(1, $body);
        self::assertSame('en', $body[0]['code']);
        self::assertTrue($body[0]['isDefault']);
        self::assertArrayHasKey('documentId', $body[0]);
    }

    public function testCanCreateADefaultLocale(): void
    {
        $ctx = self::ctx('POST', ['name' => 'French', 'code' => 'fr', 'isDefault' => true]);
        self::controller()->createLocale($ctx);

        $body = $ctx->body();
        self::assertSame('fr', $body['code']);
        self::assertSame('French', $body['name']);
        self::assertTrue($body['isDefault']);
        self::assertSame('fr', self::strapi()->service('plugin::i18n.locales')->getDefaultLocale());
        // creator fields are private
        self::assertArrayNotHasKey('createdBy', $body);
        $row = self::strapi()->db()->query('plugin::i18n.locale')->findOne(['where' => ['code' => 'fr'], 'populate' => ['createdBy']]);
        self::assertSame(self::$user['id'], $row['createdBy']['id'] ?? null);
    }

    public function testCanCreateANonDefaultLocale(): void
    {
        $ctx = self::ctx('POST', ['name' => '', 'code' => 'it', 'isDefault' => false]);
        self::controller()->createLocale($ctx);

        self::assertNull($ctx->body()['name']);
        self::assertFalse($ctx->body()['isDefault']);
        self::assertSame('en', self::strapi()->service('plugin::i18n.locales')->getDefaultLocale());
    }

    public function testCannotCreateALocaleThatAlreadyExists(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('This locale already exists');
        self::controller()->createLocale(self::ctx('POST', ['name' => 'English', 'code' => 'en', 'isDefault' => true]));
    }

    public function testCannotCreateAnUnknownLocaleCode(): void
    {
        $this->expectException(ValidationError::class);
        self::controller()->createLocale(self::ctx('POST', ['code' => 'zz-ZZ', 'isDefault' => false]));
    }

    public function testCanUpdateALocale(): void
    {
        $fr = self::strapi()->service('plugin::i18n.locales')->create(['name' => 'French', 'code' => 'fr']);
        $ctx = self::ctx('PUT', ['name' => 'Français', 'isDefault' => true], ['id' => (string) $fr['id']]);
        self::controller()->updateLocale($ctx);

        self::assertSame('Français', $ctx->body()['name']);
        self::assertTrue($ctx->body()['isDefault']);
    }

    public function testCannotUpdateTheCodeOfALocale(): void
    {
        $fr = self::strapi()->service('plugin::i18n.locales')->create(['name' => 'French', 'code' => 'fr']);

        $this->expectException(ValidationError::class);
        self::controller()->updateLocale(self::ctx('PUT', ['code' => 'ak', 'name' => 'Akan'], ['id' => (string) $fr['id']]));
    }

    public function testUpdateOfAMissingLocaleIsNotFound(): void
    {
        $ctx = self::ctx('PUT', ['name' => 'X'], ['id' => '999999']);
        self::controller()->updateLocale($ctx);

        self::assertSame(404, $ctx->status());
        self::assertSame('locale.notFound', $ctx->body()['error']['message']);
    }

    public function testCanDeleteALocale(): void
    {
        $fr = self::strapi()->service('plugin::i18n.locales')->create(['name' => 'French', 'code' => 'fr']);
        $ctx = self::ctx('DELETE', null, ['id' => (string) $fr['id']]);
        self::controller()->deleteLocale($ctx);

        self::assertSame('fr', $ctx->body()['code']);
        self::assertFalse($ctx->body()['isDefault']);
        self::assertNull(self::strapi()->service('plugin::i18n.locales')->findByCode('fr'));
    }

    public function testCannotDeleteTheDefaultLocale(): void
    {
        $en = self::strapi()->service('plugin::i18n.locales')->findByCode('en');

        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Cannot delete the default locale');
        self::controller()->deleteLocale(self::ctx('DELETE', null, ['id' => (string) $en['id']]));
    }
}
