<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Configuration;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/content-types.test.ts ("Model Configuration": the
 * configuration service factory). Upstream spies on the store utils; here the core store of a
 * booted app is read back.
 */
final class ConfigurationTest extends TestCase
{
    private static Strapi $strapi;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    private static function createCfg(?bool $isComponent = null): Configuration
    {
        return new Configuration(self::$strapi, 'test_prefix', static fn (): array => [], $isComponent);
    }

    /** @return array<string, mixed>|null */
    private static function stored(string $uid): ?array
    {
        $value = self::$strapi->store()->get(['type' => 'plugin', 'name' => 'content_manager', 'key' => "configuration_test_prefix::{$uid}"]);

        return is_array($value) ? $value : null;
    }

    public function testGetConfigurationReadsTheRightKey(): void
    {
        self::$strapi->store()->set([
            'type' => 'plugin',
            'name' => 'content_manager',
            'key' => 'configuration_test_prefix::read-uid',
            'value' => ['settings' => ['pageSize' => 20]],
        ]);

        $configuration = self::createCfg()->getConfiguration('read-uid');

        self::assertSame(['pageSize' => 20], $configuration['settings']);
        self::assertSame(['list' => [], 'edit' => []], $configuration['layouts']);
    }

    public function testSetConfigurationStoresTheRightParams(): void
    {
        self::createCfg()->setConfiguration('test-uid', ['settings' => ['a' => 1], 'layouts' => ['list' => []], 'metadatas' => ['x' => ['edit' => ['label' => 'x']]]]);

        self::assertEquals([
            'settings' => ['a' => 1],
            'layouts' => ['list' => []],
            'metadatas' => ['x' => ['edit' => ['label' => 'x']]],
            'uid' => 'test-uid',
        ], self::stored('test-uid'));
    }

    public function testSetConfigurationShouldStoreOptionalOptionsObject(): void
    {
        self::createCfg()->setConfiguration('options-uid', ['settings' => ['a' => 1], 'layouts' => [], 'metadatas' => [], 'options' => ['test' => true]]);

        $stored = self::stored('options-uid');
        self::assertSame(['test' => true], $stored['options'] ?? null);
        self::assertSame('options-uid', $stored['uid'] ?? null);
    }

    public function testSetConfigurationStoresIsComponentIfSetInFactoryOption(): void
    {
        self::createCfg(true)->setConfiguration('compo-uid', ['settings' => ['a' => 1]]);

        self::assertTrue(self::stored('compo-uid')['isComponent'] ?? null);
        self::assertArrayNotHasKey('isComponent', self::stored('test-uid') ?? []);
    }

    public function testDeleteConfigurationDeletesTheKey(): void
    {
        $cfg = self::createCfg();
        $cfg->setConfiguration('delete-uid', ['settings' => ['a' => 1]]);
        self::assertNotNull(self::stored('delete-uid'));

        $cfg->deleteConfiguration('delete-uid');

        self::assertNull(self::stored('delete-uid'));
    }
}
