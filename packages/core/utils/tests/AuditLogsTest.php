<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\AuditLogs;

/** Covers audit-logs.ts (no upstream test file). */
final class AuditLogsTest extends TestCase
{
    /** @param \Closure(string, mixed...): void $onEmit */
    private static function strapi(\Closure $onEmit, object $log): object
    {
        $eventHub = new class ($onEmit) {
            public function __construct(private \Closure $onEmit)
            {
            }

            public function emit(string $event, mixed ...$args): void
            {
                ($this->onEmit)($event, ...$args);
            }
        };

        return new class ($eventHub, $log) {
            public function __construct(private object $eventHub, private object $log)
            {
            }

            public function eventHub(): object
            {
                return $this->eventHub;
            }

            public function log(): object
            {
                return $this->log;
            }
        };
    }

    private static function logger(): object
    {
        return new class () {
            /** @var list<array{string, array<string, mixed>}> */
            public array $errors = [];

            /** @param array<string, mixed> $context */
            public function error(string $message, array $context = []): void
            {
                $this->errors[] = [$message, $context];
            }
        };
    }

    public function testEmitsTheEventWithItsPayload(): void
    {
        $emitted = [];
        $log = self::logger();
        $strapi = self::strapi(static function (string $event, mixed ...$args) use (&$emitted): void {
            $emitted[] = [$event, $args];
        }, $log);

        AuditLogs::emitAudit($strapi, 'entry.create', ['id' => 1]);

        self::assertSame([['entry.create', [['id' => 1]]]], $emitted);
        self::assertSame([], $log->errors ?? null);
    }

    public function testLogsAFailingListenerInsteadOfThrowing(): void
    {
        $error = new \RuntimeException('listener failed');
        $log = self::logger();
        $strapi = self::strapi(static function () use ($error): void {
            throw $error;
        }, $log);

        AuditLogs::emitAudit($strapi, 'entry.create', null);

        self::assertSame([['An event listener failed while handling entry.create', ['error' => $error]]], $log->errors ?? null);
    }

    public function testAcceptsUpstreamPropertyShape(): void
    {
        $log = self::logger();
        $strapi = new \stdClass();
        $strapi->eventHub = new class () {
            public function emit(string $event, mixed ...$args): void
            {
                throw new \LogicException('boom');
            }
        };
        $strapi->log = $log;

        AuditLogs::emitAudit($strapi, 'x', 1);

        self::assertCount(1, $log->errors ?? []);
    }
}
