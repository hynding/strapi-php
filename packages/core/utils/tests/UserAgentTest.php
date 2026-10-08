<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\UserAgent;

/** Port of __tests__/user-agent.test.ts. */
final class UserAgentTest extends TestCase
{
    public function testReturnsAnEmptyArrayForMissingOrInvalidInput(): void
    {
        self::assertSame([], UserAgent::parseUserAgent(null));
        self::assertSame([], UserAgent::parseUserAgent());
        self::assertSame([], UserAgent::parseUserAgent(''));
        self::assertSame([], UserAgent::parseUserAgent(123));
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function browsers(): iterable
    {
        yield 'chrome macos' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ['browser' => 'Chrome', 'os' => 'macOS', 'deviceName' => 'Chrome on macOS'],
        ];
        yield 'edge windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            ['browser' => 'Edge', 'os' => 'Windows', 'deviceName' => 'Edge on Windows'],
        ];
        yield 'firefox windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            ['browser' => 'Firefox', 'os' => 'Windows', 'deviceName' => 'Firefox on Windows'],
        ];
        yield 'safari ios' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
            ['browser' => 'Safari', 'os' => 'iOS', 'deviceName' => 'Safari on iOS'],
        ];
        yield 'chrome android' => [
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            ['browser' => 'Chrome', 'os' => 'Android', 'deviceName' => 'Chrome on Android'],
        ];
        yield 'safari macos' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            ['browser' => 'Safari', 'os' => 'macOS', 'deviceName' => 'Safari on macOS'],
        ];
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function chromiumForks(): iterable
    {
        yield 'vivaldi' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Vivaldi/6.5.3206.63',
            ['browser' => 'Vivaldi', 'os' => 'Windows', 'deviceName' => 'Vivaldi on Windows'],
        ];
        yield 'yandex' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 YaBrowser/24.1.0.0 Safari/537.36',
            ['browser' => 'Yandex Browser', 'os' => 'Windows', 'deviceName' => 'Yandex Browser on Windows'],
        ];
        yield 'brave' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Brave/1.62',
            ['browser' => 'Brave', 'os' => 'macOS', 'deviceName' => 'Brave on macOS'],
        ];
    }

    /** @param array<string, string> $expected */
    #[DataProvider('browsers')]
    public function testParses(string $ua, array $expected): void
    {
        self::assertSame($expected, UserAgent::parseUserAgent($ua));
    }

    /** @param array<string, string> $expected */
    #[DataProvider('chromiumForks')]
    public function testDetectsChromiumBasedBrowsersThatMasqueradeAsChrome(string $ua, array $expected): void
    {
        self::assertSame($expected, UserAgent::parseUserAgent($ua));
    }

    public function testClampsAbsurdlyLongUserAgentStringsBeforeScanning(): void
    {
        // Tokens placed beyond the scan limit are intentionally not detected.
        $padded = str_repeat('A', 5000) . ' Chrome/120.0.0.0 Safari/537.36';
        self::assertSame([], UserAgent::parseUserAgent($padded));
    }

    public function testFallsBackToOsOnlyOrBrowserOnlyLabels(): void
    {
        self::assertSame(['os' => 'Windows', 'deviceName' => 'Windows'], UserAgent::parseUserAgent('Mozilla/5.0 (Windows NT 10.0)'));
    }

    public function testReturnsNullDeviceNameForUnrecognizedAgents(): void
    {
        self::assertNull(UserAgent::getDeviceName('node-superagent/3.8.3'));
        self::assertNull(UserAgent::getDeviceName('curl/8.1.2'));
    }
}
