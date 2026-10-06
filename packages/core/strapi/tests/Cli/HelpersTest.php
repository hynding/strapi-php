<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Utils\Helpers;
use Strapi\Cli\Cli\Utils\Logger;

/** The helpers of packages/core/strapi/src/cli/utils/helpers.ts and the CLI logger. */
final class HelpersTest extends TestCase
{
    public function testReadableBytes(): void
    {
        self::assertSame('0', Helpers::readableBytes(0));
        self::assertSame('1.0 KB', Helpers::readableBytes(1024));
        self::assertSame('1.5 MB', Helpers::readableBytes(1.5 * 1024 * 1024));
        self::assertSame('512.0 B ', Helpers::readableBytes(512));
        self::assertSame('    1.0 KB', Helpers::readableBytes(1024, 1, 10));
    }

    public function testReadableTime(): void
    {
        self::assertSame('1.0s', Helpers::readableTime(1024));
        self::assertSame('999.0ms', Helpers::readableTime(999));
        self::assertSame('1.5m', Helpers::readableTime(90000));
        self::assertSame('1.2s', Helpers::readableTime(1299));
    }

    public function testIsStrapiProject(): void
    {
        self::assertTrue(Helpers::isStrapiProject(dirname(__DIR__, 5) . '/examples/getstarted'));
        self::assertFalse(Helpers::isStrapiProject(sys_get_temp_dir()));
    }

    public function testLoggerCountsWarningsAndErrorsAndHonoursSilent(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $logger = new Logger(silent: false, debug: false, timestamp: false, out: $out, err: $err);

        $logger->info('hello', ['a' => 1]);
        $logger->debug('hidden');
        $logger->warn('careful');
        $logger->error('boom');

        rewind($out);
        rewind($err);
        $stdout = stream_get_contents($out);
        $stderr = stream_get_contents($err);

        self::assertStringContainsString('[INFO] hello {', $stdout);
        self::assertStringNotContainsString('hidden', $stdout);
        self::assertStringContainsString('[WARN] careful', $stderr);
        self::assertStringContainsString('[ERROR] boom', $stderr);
        self::assertSame(1, $logger->warnings());
        self::assertSame(1, $logger->errors());

        $silent = Logger::createLogger(['silent' => true]);
        $silent->error('x');
        self::assertSame(1, $silent->errors());
    }
}
