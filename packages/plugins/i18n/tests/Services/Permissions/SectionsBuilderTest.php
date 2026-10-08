<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services\Permissions;

require_once __DIR__ . '/../../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\Permissions;
use Strapi\Plugin\I18n\Services\Permissions\SectionsBuilder;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/**
 * Port of server/src/services/permissions/__tests__/sections-builder.test.ts on a booted app
 * (locales `en` (default) and `fr`; `api::country.country` is localized).
 */
final class SectionsBuilderTest extends TestCase
{
    private const string LOCALIZED = 'api::country.country';

    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = I18nTestApp::boot();
        self::$strapi->service('plugin::i18n.locales')->create(['name' => 'French', 'code' => 'fr']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function builder(): SectionsBuilder
    {
        $permissions = (self::$strapi ?? throw new \LogicException('not booted'))->service('plugin::i18n.permissions');
        assert($permissions instanceof Permissions);

        return $permissions->sectionsBuilder;
    }

    /**
     * @param list<array<string, mixed>> $properties
     * @return list<array<string, mixed>>
     */
    private static function handle(string $actionId, array $properties = [], string $uid = self::LOCALIZED): array
    {
        $section = ['subjects' => [['uid' => $uid, 'properties' => $properties]]];
        self::builder()->localesPropertyHandler(['action' => ['actionId' => $actionId], 'section' => &$section]);

        return $section['subjects'][0]['properties'];
    }

    public function testAddsLocaleChildrenWithIsDefault(): void
    {
        $properties = self::handle('plugin::content-manager.explorer.read');

        self::assertCount(1, $properties);
        self::assertSame([
            ['label' => 'English (en)', 'value' => 'en', 'isDefault' => true],
            ['label' => 'French', 'value' => 'fr', 'isDefault' => false],
        ], $properties[0]['children']);
    }

    public function testUsesTheLocaleCodeAsLabelWhenNameIsAbsent(): void
    {
        $strapi = self::$strapi ?? throw new \LogicException('not booted');
        $de = $strapi->service('plugin::i18n.locales')->create(['name' => '', 'code' => 'de']);

        $children = self::handle('plugin::content-manager.explorer.read')[0]['children'];
        self::assertSame('de', $children[2]['label']);

        $strapi->db()->query('plugin::i18n.locale')->delete(['where' => ['id' => $de['id']]]);
    }

    public function testSkipsSubjectsWhereTheActionDoesNotApplyToLocales(): void
    {
        self::assertSame([], self::handle('plugin::content-manager.explorer.read', [], 'api::tag.tag'));
    }

    public function testSkipsSubjectsThatAlreadyHaveALocalesProperty(): void
    {
        $existing = ['value' => 'locales', 'label' => 'Locales', 'children' => []];
        self::assertSame([$existing], self::handle('plugin::content-manager.explorer.read', [$existing]));
    }

    public function testRegisteredOnThePermissionsLayout(): void
    {
        $strapi = self::$strapi ?? throw new \LogicException('not booted');
        $layout = $strapi->service('admin::permission')->sectionsBuilder->build($strapi->service('admin::permission')->actionProvider->values());
        $subjects = $layout['collectionTypes']['subjects'] ?? [];
        $country = array_values(array_filter($subjects, static fn (array $s): bool => $s['uid'] === self::LOCALIZED))[0] ?? null;

        self::assertNotNull($country);
        self::assertContains('locales', array_column($country['properties'], 'value'));
    }
}
