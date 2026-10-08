<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Controllers;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Controllers\ContentTypes;
use Strapi\Plugin\I18n\Tests\I18nTestApp;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/controllers/__tests__/content-types.test.ts on a booted app
 * (`api::country.country` is localized with a non-localized `visible`; `api::tag.tag` is not).
 */
final class ContentTypesTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var array<string, mixed> */
    private static array $user;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = I18nTestApp::boot();
        self::$strapi->service('plugin::i18n.locales')->create(['name' => 'French', 'code' => 'fr']);
        $id = I18nTestApp::createSuperAdmin(self::$strapi, 'i18n-ct@strapi.io')['id'];
        self::$user = self::$strapi->db()->query('admin::user')->findOne(['where' => ['id' => $id], 'populate' => ['roles']]) ?? [];
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

    /** @param array<string, mixed> $body */
    private static function call(array $body): \Strapi\Core\Services\Server\Context
    {
        $ability = self::strapi()->service('admin::permission')->engine->generateUserAbility(self::$user);
        $ctx = I18nTestApp::ctx('POST', '/i18n/content-manager/actions/get-non-localized-fields', $body, [], [], ['user' => self::$user, 'userAbility' => $ability]);
        (new ContentTypes(self::strapi()))->getNonLocalizedAttributes($ctx);

        return $ctx;
    }

    public function testModelNotLocalized(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Model api::tag.tag is not localized');
        self::call(['model' => 'api::tag.tag', 'id' => 1, 'locale' => 'fr']);
    }

    public function testEntityNotFound(): void
    {
        $ctx = self::call(['model' => 'api::country.country', 'id' => 999999, 'locale' => 'fr']);

        self::assertSame(404, $ctx->status());
    }

    public function testReturnsNonLocalizedFields(): void
    {
        $doc = self::strapi()->documents('api::country.country')->create(['data' => ['name' => 'Portugal', 'code' => 'pt', 'visible' => true], 'locale' => 'en']);
        $entry = self::strapi()->db()->query('api::country.country')->findOne(['where' => ['documentId' => $doc['documentId'], 'locale' => 'en']]);

        $ctx = self::call(['model' => 'api::country.country', 'id' => $entry['id'], 'locale' => 'fr']);

        self::assertSame([
            'nonLocalizedFields' => ['visible' => true],
            'localizations' => [['id' => $entry['id'], 'locale' => 'en', 'publishedAt' => $entry['publishedAt']]],
        ], json_decode((string) json_encode($ctx->body()), true));
    }
}
