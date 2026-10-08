<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services\Permissions;

require_once __DIR__ . '/../../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\Permissions;
use Strapi\Plugin\I18n\Services\Permissions\Actions;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/**
 * Port of server/src/services/permissions/__tests__/actions.test.ts on a booted app
 * (`api::country.country` is localized, `api::tag.tag` is not; `en` is the default locale).
 */
final class ActionsTest extends TestCase
{
    private const string READ = 'plugin::content-manager.explorer.read';

    private const string LOCALIZED = 'api::country.country';

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

    private static function actions(): Actions
    {
        $permissions = self::strapi()->service('plugin::i18n.permissions');
        assert($permissions instanceof Permissions);

        return $permissions->actions;
    }

    public function testExplorerActionsApplyToTheLocalesProperty(): void
    {
        $action = self::strapi()->service('admin::permission')->actionProvider->get(self::READ);
        self::assertContains('locales', $action['options']['applyToProperties']);
        self::assertTrue(self::strapi()->service('admin::permission')->actionProvider->appliesToProperty('locales', self::READ, self::LOCALIZED));
        self::assertFalse(self::strapi()->service('admin::permission')->actionProvider->appliesToProperty('locales', self::READ, 'api::tag.tag'));
        self::assertTrue(self::strapi()->service('admin::permission')->actionProvider->has('plugin::i18n.locale.read'));
    }

    public function testFillsInTheDefaultLocaleForEmptyLocales(): void
    {
        $result = self::actions()->normalizeRolePermissionsLocales([
            ['action' => self::READ, 'subject' => self::LOCALIZED, 'properties' => ['fields' => ['name'], 'locales' => []]],
        ]);
        self::assertSame(['en'], $result[0]['properties']['locales']);
    }

    public function testFillsInTheDefaultLocaleForMissingLocales(): void
    {
        $result = self::actions()->normalizeRolePermissionsLocales([
            ['action' => self::READ, 'subject' => self::LOCALIZED, 'properties' => ['fields' => ['name']]],
        ]);
        self::assertSame(['en'], $result[0]['properties']['locales']);
    }

    public function testLeavesNullLocalesUnchanged(): void
    {
        $result = self::actions()->normalizeRolePermissionsLocales([
            ['action' => self::READ, 'subject' => self::LOCALIZED, 'properties' => ['fields' => ['name'], 'locales' => null]],
        ]);
        self::assertNull($result[0]['properties']['locales']);
    }

    public function testLeavesPermissionsUnchangedWhenLocalesDoNotApplyToTheAction(): void
    {
        $permission = ['action' => 'plugin::content-manager.explorer.publish', 'subject' => 'api::tag.tag', 'properties' => ['locales' => []]];
        self::assertSame([$permission], self::actions()->normalizeRolePermissionsLocales([$permission]));
    }

    public function testLeavesSelectedLocalesUnchanged(): void
    {
        $result = self::actions()->normalizeRolePermissionsLocales([
            ['action' => self::READ, 'subject' => self::LOCALIZED, 'properties' => ['fields' => ['name'], 'locales' => ['en']]],
        ]);
        self::assertSame(['en'], $result[0]['properties']['locales']);
    }

    public function testLeavesNonLocalizedContentTypePermissionsUnchanged(): void
    {
        $permission = ['action' => self::READ, 'subject' => 'api::non-localized.non-localized', 'properties' => ['fields' => ['title']]];
        self::assertSame([$permission], self::actions()->normalizeRolePermissionsLocales([$permission]));
    }

    public function testNeedsLocalesPatch(): void
    {
        self::assertTrue(Actions::needsLocalesPatch([]));
        self::assertTrue(Actions::needsLocalesPatch(['locales' => []]));
        self::assertFalse(Actions::needsLocalesPatch(['locales' => null]));
        self::assertFalse(Actions::needsLocalesPatch(['locales' => ['en']]));
    }

    /** @param array<string, mixed> $properties */
    private static function insertPermission(array $properties): int
    {
        $row = self::strapi()->db()->query('admin::permission')->create(['data' => [
            'action' => self::READ,
            'subject' => self::LOCALIZED,
            'properties' => $properties,
            'conditions' => [],
        ]]);

        return (int) $row['id'];
    }

    /** @return array<string, mixed> */
    private static function properties(int $id): array
    {
        $row = self::strapi()->db()->query('admin::permission')->findOne(['where' => ['id' => $id]]);

        return is_array($row['properties'] ?? null) ? $row['properties'] : [];
    }

    /** @return array{oldContentTypes: array<string, mixed>, contentTypes: array<string, mixed>} */
    private static function justLocalized(): array
    {
        $old = self::strapi()->getModel(self::LOCALIZED)?->toArray() ?? [];
        $old['pluginOptions'] = ['i18n' => ['localized' => false]];

        return ['oldContentTypes' => [self::LOCALIZED => $old], 'contentTypes' => self::strapi()->contentTypes()];
    }

    public function testRepairPatchesMissingEmptyAndUndefinedLocalesOnly(): void
    {
        $missing = self::insertPermission(['fields' => ['name']]);
        $empty = self::insertPermission(['fields' => ['name'], 'locales' => []]);
        $selected = self::insertPermission(['locales' => ['fr']]);
        $all = self::insertPermission(['locales' => null]);
        $noProperties = self::insertPermission([]);

        self::actions()->repairPermissionsForNewlyLocalizedTypes(self::justLocalized());

        self::assertSame(['fields' => ['name'], 'locales' => ['en']], self::properties($missing));
        self::assertSame(['fields' => ['name'], 'locales' => ['en']], self::properties($empty));
        self::assertSame(['locales' => ['fr']], self::properties($selected));
        self::assertSame(['locales' => null], self::properties($all));
        self::assertSame(['locales' => ['en']], self::properties($noProperties));
    }

    public function testRepairDoesNothingWhenTheContentTypeWasAlreadyLocalized(): void
    {
        $id = self::insertPermission(['fields' => ['name']]);

        self::actions()->repairPermissionsForNewlyLocalizedTypes([
            'oldContentTypes' => [self::LOCALIZED => self::strapi()->getModel(self::LOCALIZED)?->toArray()],
            'contentTypes' => self::strapi()->contentTypes(),
        ]);
        self::actions()->repairPermissionsForNewlyLocalizedTypes(['oldContentTypes' => null, 'contentTypes' => self::strapi()->contentTypes()]);

        self::assertSame(['fields' => ['name']], self::properties($id));
    }
}
