<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Utils\CappedWarnings;

/** Port of src/utils/__tests__/capped-warnings.test.ts */
final class CappedWarningsTest extends TestCase
{
    public function testEmitsEveryWarningWhenUnderTheLimit(): void
    {
        $calls = [];
        $reporter = CappedWarnings::createCappedWarningReporter(static function (string $m) use (&$calls): void {
            $calls[] = $m;
        }, 3);

        $reporter->warn('one');
        $reporter->warn('two');

        self::assertSame(['one', 'two'], $calls);
    }

    public function testEmitsASuppressionNoticeOnceTheLimitIsReached(): void
    {
        $calls = [];
        $reporter = CappedWarnings::createCappedWarningReporter(static function (string $m) use (&$calls): void {
            $calls[] = $m;
        }, 2);

        $reporter->warn('one');
        $reporter->warn('two');
        $reporter->warn('three');
        $reporter->warn('four');

        self::assertCount(3, $calls);
        self::assertSame('one', $calls[0]);
        self::assertSame('two', $calls[1]);
        self::assertStringContainsString('Further detailed warnings suppressed after 2 messages', $calls[2]);
    }

    public function testDefaultsToDefaultDetailedWarningLimit(): void
    {
        $count = 0;
        $reporter = CappedWarnings::createCappedWarningReporter(static function () use (&$count): void {
            $count++;
        });

        for ($i = 0; $i < CappedWarnings::DEFAULT_DETAILED_WARNING_LIMIT + 1; $i++) {
            $reporter->warn("warning-{$i}");
        }

        self::assertSame(CappedWarnings::DEFAULT_DETAILED_WARNING_LIMIT + 1, $count);
    }

    public function testIsANoOpWhenOnWarningIsOmitted(): void
    {
        $reporter = CappedWarnings::createCappedWarningReporter();
        $reporter->warn('ignored');

        $this->expectNotToPerformAssertions();
    }
}
