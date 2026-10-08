<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Controllers;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Controllers\Settings;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/** Port of server/src/controllers/__tests__/settings.test.ts on a booted app (the settings live in the core store). */
final class SettingsTest extends TestCase
{
    private static ?Strapi $strapi = null;

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

    public function testExposesTheStoredSettingsAndReportsAiLocalizationsAsAvailable(): void
    {
        self::strapi()->service('plugin::i18n.settings')->setSettings(['aiLocalizations' => ['enabled' => true]]);
        $provider = new class () {
            public string $name = 'byok';
        };
        $aiAdmin = new class () {
            public function isAvailable(): bool
            {
                return true;
            }

            public function authorizeCustomProvider(): bool
            {
                return true;
            }
        };
        $previous = self::strapi()->has('ai.admin') ? self::strapi()->get('ai.admin') : null;
        self::strapi()->set('ai.admin', $aiAdmin);
        self::strapi()->get('services')->set('plugin::i18n.ai-translations', static fn (Strapi $s): object => new \Strapi\Plugin\I18n\Services\AiTranslations($s));
        self::strapi()->service('plugin::i18n.ai-translations')->registerProvider(['provider' => $provider]);

        $ctx = I18nTestApp::ctx();
        (new Settings(self::strapi()))->getSettings($ctx);

        self::assertSame(['data' => ['aiLocalizations' => ['enabled' => true], 'aiLocalizationsAvailable' => true]], $ctx->body());

        if ($previous !== null) {
            self::strapi()->set('ai.admin', $previous);
        }
        self::strapi()->get('services')->set('plugin::i18n.ai-translations', static fn (Strapi $s): object => new \Strapi\Plugin\I18n\Services\AiTranslations($s));
    }

    public function testReportsAiLocalizationsAsUnavailableWhenNoProviderIsRegistered(): void
    {
        $ctx = I18nTestApp::ctx();
        (new Settings(self::strapi()))->getSettings($ctx);

        self::assertFalse($ctx->body()['data']['aiLocalizationsAvailable']);
    }

    public function testUpdateSettingsStoresTheValidatedSettings(): void
    {
        $ctx = I18nTestApp::ctx('PUT', '/i18n/settings', ['aiLocalizations' => true]);
        (new Settings(self::strapi()))->updateSettings($ctx);

        self::assertSame(['data' => ['aiLocalizations' => true]], $ctx->body());
        self::assertSame(['aiLocalizations' => true], self::strapi()->service('plugin::i18n.settings')->getSettings());
    }
}
