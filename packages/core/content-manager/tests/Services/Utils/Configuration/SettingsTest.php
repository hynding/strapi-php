<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils\Configuration;

require_once __DIR__ . '/../../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Configuration\Settings;
use Strapi\ContentManager\Tests\StubStrapi;

/**
 * Port of server/src/services/utils/configuration/__tests__/settings.test.ts.
 *
 * Upstream mocks `traverseQuerySort` (identity) and spies on `createDefaultSettings`; here the real
 * traversal runs and "creates default settings" is asserted on the result.
 */
final class SettingsTest extends TestCase
{
    private const array DEFAULTS = [
        'bulkable' => true,
        'filterable' => true,
        'searchable' => true,
        'pageSize' => 10,
        'relationOpenMode' => 'modal',
        'mainField' => 'id',
        'defaultSortBy' => 'id',
        'defaultSortOrder' => 'ASC',
    ];

    public function testConsistentDefaults(): void
    {
        $settings = Settings::createDefaultSettings(['attributes' => []]);

        self::assertEquals(self::DEFAULTS, $settings);
    }

    public function testUsesIdAsMainFieldByDefault(): void
    {
        $settings = Settings::createDefaultSettings(['attributes' => []]);

        self::assertSame('id', $settings['mainField']);
        self::assertSame('id', $settings['defaultSortBy']);
    }

    public function testUsesFirstStringAttributeThatIsNotId(): void
    {
        $settings = Settings::createDefaultSettings(['attributes' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']]]);

        self::assertSame('title', $settings['mainField']);
        self::assertSame('title', $settings['defaultSortBy']);
    }

    public function testUsesOverridesConfiguredInSchemaConfig(): void
    {
        $settings = Settings::createDefaultSettings([
            'attributes' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']],
            'config' => ['settings' => ['searchable' => false, 'filterable' => false, 'bulkable' => false]],
        ]);

        self::assertFalse($settings['searchable']);
        self::assertFalse($settings['filterable']);
        self::assertFalse($settings['bulkable']);
    }

    public function testOverridesCannotAddNewProperties(): void
    {
        $settings = Settings::createDefaultSettings([
            'attributes' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']],
            'config' => ['settings' => ['searchable' => false, 'filterable' => false, 'bulkable' => false, 'newProperty' => 'test']],
        ]);

        self::assertArrayNotHasKey('newProperty', $settings);
        self::assertFalse($settings['searchable']);
    }

    public function testSyncCreatesDefaultSettingIfEmpty(): void
    {
        $settings = Settings::syncSettings(StubStrapi::create(), [], ['attributes' => []]);

        self::assertEquals(self::DEFAULTS, $settings);
    }

    public function testSyncReusesTheExistingConfig(): void
    {
        $settings = Settings::syncSettings(StubStrapi::create(), ['settings' => ['searchable' => false, 'bulkable' => true]], ['attributes' => []]);

        self::assertSame('id', $settings['mainField']);
        self::assertSame('id', $settings['defaultSortBy']);
        self::assertFalse($settings['searchable']);
        self::assertTrue($settings['bulkable']);
    }

    public function testSyncResetsMainFieldAndDefaultSortByIfNotSortableAnymore(): void
    {
        $schema = ['uid' => 'api::article.article', 'attributes' => ['title' => ['type' => 'string'], 'content' => ['type' => 'json']]];
        $settings = Settings::syncSettings(
            StubStrapi::create(),
            ['settings' => ['mainField' => 'content', 'defaultSortBy' => 'content', 'searchable' => false, 'bulkable' => true]],
            $schema,
        );

        self::assertSame('title', $settings['mainField']);
        self::assertSame('title', $settings['defaultSortBy']);
        self::assertFalse($settings['searchable']);
    }
}
