<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Ai\Controllers;

require_once __DIR__ . '/../../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Ai\Controllers\Ai;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/**
 * Port of server/src/ai/controllers/__tests__/ai.test.ts. Without an Enterprise license (never in
 * the PHP port) every AI route answers 404, as upstream's "AI is not enabled" cases.
 */
final class AiTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function controller(): Ai
    {
        return new Ai(self::$strapi ?? throw new \LogicException('not booted'));
    }

    /** @return iterable<string, array{string}> */
    public static function actions(): iterable
    {
        yield 'getAiToken returns notFound' => ['getAiToken'];
        yield 'getAiUsage returns notFound' => ['getAiUsage'];
        yield 'getAiFeatureConfig returns notFound' => ['getAiFeatureConfig'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function testReturnsNotFoundWhenAiIsNotAvailable(string $action): void
    {
        $ctx = BootedAdminApp::ctx();
        $ctx->state()->set('user', ['id' => 1]);

        self::controller()->{$action}($ctx);

        self::assertSame(404, $ctx->status());
        self::assertSame('NotFoundError', $ctx->body()['error']['name'] ?? null);
    }
}
