<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Logger;

use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/logger/__tests__/logger.test.ts (output streams instead of console mocks). */
final class LoggerTest extends TestCase
{
    private const PREFIX = "\t\\[\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}Z\\] ";

    public function testInitializesWithDefaultValues(): void
    {
        $logger = Logger::loggerFactory();

        self::assertFalse($logger->isDebug);
        self::assertFalse($logger->isSilent);
        self::assertSame(0, $logger->errors());
        self::assertSame(0, $logger->warnings());
    }

    public function testInitializesWithProvidedOptions(): void
    {
        $logger = Logger::loggerFactory(['debug' => true, 'silent' => true]);

        self::assertTrue($logger->isDebug);
        self::assertTrue($logger->isSilent);
    }

    public function testSetters(): void
    {
        $logger = Logger::loggerFactory();

        self::assertTrue($logger->setDebug(true)->isDebug);
        self::assertTrue($logger->setSilent(true)->isSilent);
    }

    public function testDebugMessages(): void
    {
        [$logger, $out] = self::memoryLogger(debug: true);

        $logger->debug('Test debug message');
        self::assertMatchesRegularExpression('/^\[DEBUG\]' . self::PREFIX . 'Test debug message\n$/', self::read($out));

        [$quiet, $quietOut] = self::memoryLogger(debug: false);
        $quiet->debug('hidden');
        $logger->setSilent(true)->debug('hidden');
        self::assertSame('', self::read($quietOut));
    }

    public function testErrorMessages(): void
    {
        [$logger, , $err] = self::memoryLogger();

        $logger->error('Test error message');
        self::assertMatchesRegularExpression('/^\[ERROR\]' . self::PREFIX . 'Test error message\n$/', self::read($err));
        self::assertSame(1, $logger->errors());

        [$silent, , $silentErr] = self::memoryLogger(silent: true);
        $silent->error('x');
        self::assertSame('', self::read($silentErr));
        self::assertSame(1, $silent->errors());
    }

    public function testInfoMessages(): void
    {
        [$logger, $out] = self::memoryLogger();

        $logger->info('Test info message');
        self::assertMatchesRegularExpression('/^\[INFO\]' . self::PREFIX . 'Test info message\n$/', self::read($out));
    }

    public function testRawMode(): void
    {
        [$logger, $out] = self::memoryLogger();

        $logger->raw('Test raw message');
        $logger->setSilent(true)->raw('hidden');
        self::assertSame("Test raw message\n", self::read($out));
    }

    public function testWarnings(): void
    {
        [$logger, , $err] = self::memoryLogger();

        $logger->warn('Test warning message');
        self::assertMatchesRegularExpression('/^\[WARN\]' . self::PREFIX . 'Test warning message\n$/', self::read($err));
        self::assertSame(1, $logger->warnings());
        self::assertNull($logger->setSilent(true)->stdout());
    }
}
