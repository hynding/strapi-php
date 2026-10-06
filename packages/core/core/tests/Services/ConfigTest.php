<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Strapi\Core\Services\Config;

/** Port of packages/core/core/src/registries/__tests__/config.test.ts. */
final class ConfigTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private function provider(array $initial): Config
    {
        $this->warnings = [];
        $warnings = &$this->warnings;
        $logger = new class($warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };

        return Config::createConfigProvider($initial, static fn () => $logger);
    }

    public function testReturnsObjectsForPartialPaths(): void
    {
        $config = $this->provider(['default' => ['child' => 'val']]);
        self::assertSame(['child' => 'val'], $config->get('default'));
    }

    public function testSupportsFullStringPaths(): void
    {
        $config = $this->provider(['default' => ['child' => 'val']]);
        self::assertSame('val', $config->get('default.child'));
    }

    public function testSupportsArrayPaths(): void
    {
        $config = $this->provider(['default' => ['child' => 'val']]);
        self::assertSame('val', $config->get(['default', 'child']));
    }

    public function testAcceptsInitialValues(): void
    {
        $config = $this->provider(['default' => 'val', 'foo' => 'bar']);
        self::assertSame('val', $config->get('default'));
        self::assertSame('bar', $config->get('foo'));
    }

    public function testAcceptsUidInPaths(): void
    {
        $config = $this->provider(['api::myapi' => ['foo' => 'val'], 'plugin::myplugin' => ['foo' => 'bar']]);

        self::assertSame('val', $config->get('api::myapi.foo'));
        self::assertSame(['foo' => 'val'], $config->get('api::myapi'));
        self::assertSame('bar', $config->get('plugin::myplugin.foo'));
        self::assertSame(['foo' => 'bar'], $config->get('plugin::myplugin'));
    }

    public function testGetSupportsPluginDotPrefixWithDeprecation(): void
    {
        $config = $this->provider(['plugin::myplugin' => ['foo' => 'bar']]);

        self::assertSame('bar', $config->get('plugin.myplugin.foo'));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('deprecated', $this->warnings[0]);
    }

    public function testGetSupportsPluginModelInArrayPathWithoutDeprecation(): void
    {
        $config = $this->provider(['plugin::myplugin' => ['foo' => 'bar']]);

        self::assertSame('bar', $config->get(['plugin::myplugin', 'foo']));
        self::assertSame([], $this->warnings);
    }

    public function testGetSupportsPluginDotModelPrefixInArrayPath(): void
    {
        $config = $this->provider(['plugin::myplugin' => ['foo' => 'bar']]);

        self::assertSame('bar', $config->get(['plugin.myplugin', 'foo']));
        self::assertNotEmpty($this->warnings);
    }

    public function testGetSupportsPluginPlusModelInArrayPath(): void
    {
        $config = $this->provider(['plugin::myplugin' => ['foo' => 'bar']]);

        self::assertSame('bar', $config->get(['plugin', 'myplugin', 'foo']));
        self::assertNotEmpty($this->warnings);
    }

    public function testSetSupportsPluginDotPrefixInStringPath(): void
    {
        $config = $this->provider(['plugin::myplugin' => ['foo' => 'bar']]);
        $config->set('plugin.myplugin.thing', 'val');

        self::assertNotEmpty($this->warnings);
        self::assertSame('val', $config->get('plugin::myplugin.thing'));
    }

    public function testSetSupportsPluginDotPrefixInArrayPath(): void
    {
        $config = $this->provider(['plugin::myplugin' => ['foo' => 'bar']]);
        $config->set(['plugin.myplugin', 'thing'], 'val');

        self::assertNotEmpty($this->warnings);
        self::assertSame('val', $config->get('plugin::myplugin.thing'));
    }

    public function testHasAndDefault(): void
    {
        $config = $this->provider(['a' => ['b' => null]]);

        self::assertTrue($config->has('a'));
        self::assertFalse($config->has('a.c'));
        self::assertSame('dflt', $config->get('a.c', 'dflt'));
        self::assertSame(['a' => ['b' => null]], $config->all());
    }
}
