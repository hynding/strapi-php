<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailSendmail\DirectSmtp;

/** Port of __tests__/direct-smtp.vitest.test.ts (`vi.mock('dns/promises')` → DirectSmtp::$resolveMx). */
final class DirectSmtpTest extends TestCase
{
    /** @var list<string> */
    private array $resolveMxCalls = [];

    /** @var list<\Closure(string): list<array{exchange: string, priority: int}>> */
    private array $resolveMxQueue = [];

    protected function setUp(): void
    {
        $calls = &$this->resolveMxCalls;
        $queue = &$this->resolveMxQueue;
        DirectSmtp::$resolveMx = static function (string $domain) use (&$calls, &$queue): array {
            $calls[] = $domain;
            $next = array_shift($queue);
            if ($next === null) {
                throw new \RuntimeException('unexpected resolveMx call');
            }

            return $next($domain);
        };
    }

    protected function tearDown(): void
    {
        DirectSmtp::$resolveMx = null;
    }

    /** @param list<array{exchange: string, priority: int}> $records */
    private function resolvesOnce(array $records): void
    {
        $this->resolveMxQueue[] = static fn (): array => $records;
    }

    public function testUsesDevHostWhenDevPortIsSet(): void
    {
        self::assertSame([['exchange' => '127.0.0.1']], DirectSmtp::resolveMxHosts('example.com', ['devPort' => 1025, 'devHost' => '127.0.0.1']));
        self::assertSame([], $this->resolveMxCalls);
    }

    public function testDefaultsDevHostToLocalhostWhenDevPortIsSet(): void
    {
        self::assertSame([['exchange' => 'localhost']], DirectSmtp::resolveMxHosts('example.com', ['devPort' => 1025]));
    }

    public function testTreatsDevPort0AsMxMode(): void
    {
        $this->resolvesOnce([['exchange' => 'mx.example.com', 'priority' => 0]]);

        $hosts = DirectSmtp::resolveMxHosts('example.com', ['devPort' => 0]);

        self::assertSame(['example.com'], $this->resolveMxCalls);
        self::assertSame(['mx.example.com'], array_column($hosts, 'exchange'));
    }

    public function testUsesDevModeWhenDevPortIsTrue(): void
    {
        self::assertSame([['exchange' => '127.0.0.1']], DirectSmtp::resolveMxHosts('example.com', ['devPort' => true, 'devHost' => '127.0.0.1']));
        self::assertSame([], $this->resolveMxCalls);
    }

    public function testResolvesMxRecordsSortedByPriority(): void
    {
        $this->resolvesOnce([
            ['exchange' => 'mx20.example.com.', 'priority' => 20],
            ['exchange' => 'mx10.example.com.', 'priority' => 10],
        ]);

        $hosts = DirectSmtp::resolveMxHosts('example.com', []);

        self::assertSame(['example.com'], $this->resolveMxCalls);
        self::assertSame(['mx10.example.com', 'mx20.example.com'], array_column($hosts, 'exchange'));
    }

    public function testStripsTrailingDotFromMxExchange(): void
    {
        $this->resolvesOnce([['exchange' => 'mx.example.com.', 'priority' => 0]]);

        self::assertSame([['exchange' => 'mx.example.com']], DirectSmtp::resolveMxHosts('example.com', []));
    }

    public function testAppendsSmtpHostWhenSetToAString(): void
    {
        $this->resolvesOnce([['exchange' => 'mx.example.com', 'priority' => 0]]);

        self::assertSame(['mx.example.com', 'relay.extra.com'], array_column(DirectSmtp::resolveMxHosts('example.com', ['smtpHost' => 'relay.extra.com']), 'exchange'));
    }

    public function testAppendsSmtpHostWhenSetToANumber(): void
    {
        $this->resolvesOnce([['exchange' => 'mx.example.com', 'priority' => 0]]);

        self::assertSame(['mx.example.com', '2525'], array_column(DirectSmtp::resolveMxHosts('example.com', ['smtpHost' => 2525]), 'exchange'));
    }

    public function testTreatsSmtpHost0AsDisabled(): void
    {
        $this->resolvesOnce([['exchange' => 'mx.example.com', 'priority' => 0]]);

        self::assertSame(['mx.example.com'], array_column(DirectSmtp::resolveMxHosts('example.com', ['smtpHost' => 0]), 'exchange'));
    }

    public function testThrowsWhenMxResolutionReturnsEmpty(): void
    {
        $this->resolvesOnce([]);

        $this->expectExceptionMessage('can not resolve Mx');
        DirectSmtp::resolveMxHosts('example.com', []);
    }

    public function testWrapsDnsErrorsWithContext(): void
    {
        $this->resolveMxQueue[] = static fn (): never => throw new \RuntimeException('ENOTFOUND');

        $this->expectExceptionMessage('can not resolve Mx of <example.com>: ENOTFOUND');
        DirectSmtp::resolveMxHosts('example.com', []);
    }

    public function testSendDirectSmtpRejectsWhenThereAreNoRecipients(): void
    {
        $this->expectExceptionMessage('No recipients defined');

        DirectSmtp::sendDirectSmtp([
            'from' => 'Sender <sender@from.com>',
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Hello',
            'text' => 'Body',
            'html' => '<p>Body</p>',
        ], []);
    }
}
