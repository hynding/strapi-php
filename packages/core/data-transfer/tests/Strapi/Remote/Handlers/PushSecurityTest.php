<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Strapi\Remote\Handlers;

use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Strapi\Remote\Handlers\Push;
use Strapi\DataTransfer\Tests\BootedAppTestCase;
use Strapi\DataTransfer\Utils\Stream\Writable;

/**
 * Port of src/strapi/remote/handlers/__tests__/push-security.test.ts. The provider of the last case
 * is a real, bootstrapped local destination (upstream passes jest mocks for rollback/close).
 */
final class PushSecurityTest extends BootedAppTestCase
{
    public function testDoesNotWriteAnyEntityFromAMixedProtectedBatch(): void
    {
        $writes = [];
        $stream = new Writable(write: static function (mixed $chunk) use (&$writes): void {
            $writes[] = $chunk;
        });
        $stats = ['started' => 0, 'finished' => 0];

        try {
            Push::writeValidatedPushStreamBatch(self::strapi(), 'entities', [
                ['type' => 'api::article.article', 'id' => 1, 'data' => ['title' => 'allowed first item']],
                ['type' => 'admin::user', 'id' => 2, 'data' => []],
            ], $stream, $stats);
            self::fail('Expected the batch to be rejected');
        } catch (\Throwable $e) {
            self::assertMatchesRegularExpression('/admin::user/', $e->getMessage());
        }

        self::assertSame([], $writes);
        self::assertSame(['started' => 0, 'finished' => 0], $stats);
    }

    public function testDoesNotCloseAnInitializedButNotBootstrappedLocalProvider(): void
    {
        $provider = LocalDestination::createLocalStrapiDestinationProvider([
            'strategy' => 'restore',
            'restore' => [],
            'autoDestroy' => false,
            'getStrapi' => static fn () => null,
        ]);
        $cleanups = 0;

        Push::abortPushTransfer(self::strapi(), [
            'provider' => $provider,
            'streams' => ['entities' => new Writable(write: static function (): void {
            })],
            'assets' => ['one' => ['stream' => new Writable(write: static function (): void {
            })]],
            'cleanup' => static function () use (&$cleanups): void {
                $cleanups++;
            },
        ]);

        self::assertSame(1, $cleanups);
        self::assertNull($provider->strapi);
    }

    public function testContinuesRollbackCloseAndCleanupWhenAStreamDestructionFails(): void
    {
        $strapi = self::strapi();
        $provider = LocalDestination::createLocalStrapiDestinationProvider([
            'strategy' => 'restore',
            'restore' => ['entities' => ['include' => []]],
            'autoDestroy' => false,
            'getStrapi' => static fn () => $strapi,
        ]);
        $provider->bootstrap();
        $cleanups = 0;

        $brokenStream = new Writable(
            write: static function (): void {
            },
            destroy: static function (): void {
                throw new \RuntimeException('stream destruction failed');
            },
        );

        Push::abortPushTransfer($strapi, [
            'provider' => $provider,
            'streams' => ['entities' => $brokenStream],
            'assets' => [],
            'cleanup' => static function () use (&$cleanups): void {
                $cleanups++;
            },
        ]);

        self::assertSame(1, $cleanups);
        // close() ended the transfer: lifecycles run again and the database is usable outside the transaction
        self::assertSame(0, $strapi->db()->query('api::category.category')->count([]));
    }
}
