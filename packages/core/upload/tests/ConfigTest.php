<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Port of server/src/__tests__/config.test.ts. */
final class ConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(): array
    {
        return require dirname(__DIR__) . '/server/src/config.php';
    }

    public function testConcurrentUploadSizeDefaultsTo1(): void
    {
        self::assertSame(1, self::config()['default']['concurrentUploadSize']);
    }

    public function testConcurrentUploadRequestsDefaultsTo1(): void
    {
        self::assertSame(1, self::config()['default']['concurrentUploadRequests']);
    }

    public function testAcceptsAnUndefinedConfig(): void
    {
        self::config()['validator']([]);
        $this->addToAssertionCount(1);
    }

    /** @return list<array{string}> */
    public static function keys(): array
    {
        return [['concurrentUploadSize'], ['concurrentUploadRequests']];
    }

    #[DataProvider('keys')]
    public function testAcceptsAValidIntegerGreaterOrEqualTo1(string $key): void
    {
        self::config()['validator']([$key => 1]);
        self::config()['validator']([$key => 5]);
        $this->addToAssertionCount(2);
    }

    /** @return list<array{string, string, mixed}> */
    public static function invalidValues(): array
    {
        $cases = [];
        foreach (['concurrentUploadSize', 'concurrentUploadRequests'] as $key) {
            foreach ([['zero', 0], ['negative', -3], ['float', 2.5], ['string', '5'], ['boolean', true], ['null', null]] as [$label, $value]) {
                $cases["{$key} rejects {$label}"] = [$key, $label, $value];
            }
        }

        return array_values($cases);
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(string $key, string $label, mixed $value): void
    {
        $this->expectExceptionMessageMatches("/{$key}/");
        self::config()['validator']([$key => $value]);
    }
}
