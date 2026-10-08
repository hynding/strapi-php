<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Sessions;

/** Port of __tests__/sessions.test.ts. */
final class SessionsTest extends TestCase
{
    public function testSanitizeSessionEntryMapsSessionFieldsAndFlagsTheCurrentSession(): void
    {
        $result = Sessions::sanitizeSessionEntry(
            [
                'sessionId' => 'session-a',
                'deviceId' => 'device-a',
                'createdAt' => '2026-06-12T10:00:00.000Z',
                'metadata' => [
                    'loginAt' => '2026-06-12T08:00:00.000Z',
                    'deviceName' => 'Chrome on macOS',
                ],
            ],
            'session-a',
        );

        self::assertSame([
            'id' => 'session-a',
            'deviceId' => 'device-a',
            'deviceName' => 'Chrome on macOS',
            'current' => true,
            'loginAt' => '2026-06-12T08:00:00.000Z',
            'lastActiveAt' => '2026-06-12T10:00:00.000Z',
        ], $result);
    }

    public function testSanitizeSessionEntryIgnoresInvalidMetadataTypes(): void
    {
        $result = Sessions::sanitizeSessionEntry([
            'sessionId' => 'session-b',
            'metadata' => ['loginAt' => 123, 'deviceName' => false],
        ]);

        self::assertSame(['id' => 'session-b', 'current' => false], $result);
    }

    public function testSanitizeSessionEntryNormalizesCreatedAtToIsoUtc(): void
    {
        $fromDate = Sessions::sanitizeSessionEntry([
            'sessionId' => 's',
            'createdAt' => new \DateTimeImmutable('2026-06-12 12:00:00.123456', new \DateTimeZone('Europe/Paris')),
        ]);
        self::assertSame('2026-06-12T10:00:00.123Z', $fromDate['lastActiveAt'] ?? null);

        $fromMillis = Sessions::sanitizeSessionEntry(['sessionId' => 's', 'createdAt' => 1781258400123]);
        self::assertSame('2026-06-12T10:00:00.123Z', $fromMillis['lastActiveAt'] ?? null);

        self::assertSame('1969-12-31T23:59:59.999Z', Sessions::toISOString(-1));
    }

    public function testToISOStringThrowsOnAnInvalidDate(): void
    {
        $this->expectException(\RangeException::class);
        $this->expectExceptionMessage('Invalid time value');
        Sessions::toISOString('not a date');
    }

    public function testBuildSessionMetadataCapturesLoginTimeAndADerivedDeviceLabelWithoutIp(): void
    {
        $result = Sessions::buildSessionMetadata([
            'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'loginAt' => '2026-06-12T08:00:00.000Z',
        ]);

        self::assertSame(['loginAt' => '2026-06-12T08:00:00.000Z', 'deviceName' => 'Chrome on macOS'], $result);
        self::assertArrayNotHasKey('ip', $result);
    }

    public function testBuildSessionMetadataDefaultsLoginAtToNow(): void
    {
        $result = Sessions::buildSessionMetadata(['userAgent' => 'curl/8.1.2']);

        self::assertSame(['loginAt'], array_keys($result));
        self::assertIsString($result['loginAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $result['loginAt']);
    }

    public function testSortSessionsForDisplayPutsTheCurrentSessionFirstThenMostRecentlyUsed(): void
    {
        $sorted = Sessions::sortSessionsForDisplay([
            ['id' => 'old', 'current' => false, 'lastActiveAt' => '2026-06-10T08:00:00.000Z'],
            ['id' => 'current', 'current' => true, 'lastActiveAt' => '2026-06-11T08:00:00.000Z'],
            ['id' => 'recent', 'current' => false, 'lastActiveAt' => '2026-06-12T08:00:00.000Z'],
        ]);

        self::assertSame(['current', 'recent', 'old'], array_column($sorted, 'id'));
    }
}
