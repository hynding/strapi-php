<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailSendmail\Logger;

/** Port of __tests__/logger.vitest.test.ts. */
final class LoggerTest extends TestCase
{
    public function testUsesCustomLoggerEvenWhenSilentIsTrue(): void
    {
        $calls = [];
        $logger = Logger::createLogger([
            'silent' => true,
            'logger' => ['debug' => static function (mixed ...$args) use (&$calls): void {
                $calls[] = $args;
            }],
        ]);
        $logger->debug('msg');

        self::assertSame([['msg']], $calls);
    }

    public function testNoopsMissingMethodsOnAPartialCustomLogger(): void
    {
        $logger = Logger::createLogger(['silent' => false, 'logger' => ['error' => static function (): void {
        }]]);
        $logger->debug('x');

        $this->addToAssertionCount(1);
    }

    public function testSuppressesOutputWhenSilentAndNoCustomLogger(): void
    {
        $logger = Logger::createLogger(['silent' => true]);
        $logger->error('e');

        $this->addToAssertionCount(1);
    }

    public function testAcceptsAPsrLogger(): void
    {
        $psr = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = "{$level}: {$message}";
            }
        };
        $logger = Logger::createLogger(['logger' => $psr]);
        $logger->warn('careful');
        $logger->error('failed', new \RuntimeException('x'));

        self::assertSame(['warning: careful', 'error: failed'], $psr->records);
    }
}
