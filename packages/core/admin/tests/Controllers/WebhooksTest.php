<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\Webhooks;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/__tests__/webhooks.test.ts, against a booted app. */
final class WebhooksTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<array{0: string, 1: mixed}> */
    private static array $events = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        foreach (['webhook.create', 'webhook.update', 'webhook.delete'] as $event) {
            self::$strapi->eventHub()->on($event, static function (mixed $payload) use ($event): void {
                self::$events[] = [$event, $payload];
            });
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        self::$events = [];
    }

    private static function controller(): Webhooks
    {
        return new Webhooks(self::$strapi ?? throw new \LogicException('not booted'));
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $params */
    private static function ctx(?array $body = null, array $params = []): Context
    {
        $ctx = BootedAdminApp::ctx('POST', '/admin/webhooks', $body);
        if ($body !== null) {
            $ctx->setRequestBody($body);
        }
        $ctx->setParams($params);

        return $ctx;
    }

    /** @return array<string, mixed> */
    private static function createWebhook(array $overrides = []): array
    {
        $ctx = self::ctx([
            'name' => 'Deploy site',
            'url' => 'https://example.com/hook',
            'headers' => ['Authorization' => 'Bearer s3cret'],
            'events' => ['entry.create'],
            ...$overrides,
        ]);
        self::controller()->createWebhook($ctx);
        self::assertSame(201, $ctx->status());

        return $ctx->body()['data'];
    }

    public function testCreateWebhookEmitsWebhookCreateWithHeaderNamesOnly(): void
    {
        $webhook = self::createWebhook();

        self::assertSame(['webhook.create', [
            'webhookId' => $webhook['id'],
            'name' => 'Deploy site',
            'url' => 'https://example.com',
            'events' => ['entry.create'],
            'headers' => ['Authorization'],
            'isEnabled' => true,
        ]], self::$events[0]);
    }

    public function testUpdateWebhookEmitsWebhookUpdateWithTheChangesAndNothingWhenUnchanged(): void
    {
        $webhook = self::createWebhook();
        $body = ['name' => 'Deploy site', 'url' => 'https://example.com/hook', 'headers' => ['Authorization' => 'Bearer s3cret'], 'events' => ['entry.create'], 'isEnabled' => false];
        self::$events = [];

        $ctx = self::ctx($body, ['id' => $webhook['id']]);
        self::controller()->updateWebhook($ctx);
        self::assertFalse($ctx->body()['data']['isEnabled']);
        self::assertSame(['webhook.update', ['webhookId' => $webhook['id'], 'name' => 'Deploy site', 'changes' => ['isEnabled' => ['before' => true, 'after' => false]]]], self::$events[0]);

        self::$events = [];
        self::controller()->updateWebhook(self::ctx($body, ['id' => $webhook['id']]));
        self::assertSame([], self::$events);
    }

    public function testDeleteWebhookEmitsWebhookDelete(): void
    {
        $webhook = self::createWebhook();
        self::$events = [];

        $ctx = self::ctx(null, ['id' => $webhook['id']]);
        self::controller()->deleteWebhook($ctx);

        self::assertSame($webhook['id'], $ctx->body()['data']['id']);
        self::assertSame([['webhook.delete', ['webhookId' => $webhook['id'], 'name' => 'Deploy site']]], self::$events);

        $ctx = self::ctx(null, ['id' => $webhook['id']]);
        self::controller()->deleteWebhook($ctx);
        self::assertSame(404, $ctx->status());
        self::assertSame('webhook.notFound', $ctx->body()['error']['message']);
    }

    public function testDeleteWebhooksEmitsOneWebhookDeletePerDeletedWebhookAndSkipsUnknownIds(): void
    {
        $a = self::createWebhook(['name' => 'a']);
        $b = self::createWebhook(['name' => 'b']);
        self::$events = [];

        $ctx = self::ctx(['ids' => [$a['id'], '424242', $b['id']]]);
        self::controller()->deleteWebhooks($ctx);

        self::assertSame([$a['id'], '424242', $b['id']], $ctx->body()['data']);
        self::assertSame(['a', 'b'], array_map(static fn (array $e): mixed => $e[1]['name'], self::$events));

        $ctx = self::ctx(['ids' => []]);
        self::controller()->deleteWebhooks($ctx);
        self::assertSame(400, $ctx->status());
        self::assertSame('ids must be an array of id', $ctx->body()['error']['message']);
    }

    public function testRejectsAnInvalidUrlAndUnknownKeys(): void
    {
        try {
            self::controller()->createWebhook(self::ctx(['name' => 'x', 'url' => 'not a url', 'headers' => [], 'events' => []]));
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('url must be a valid URL', $e->getMessage());
        }

        $this->expectException(ValidationError::class);
        self::controller()->createWebhook(self::ctx(['name' => 'x', 'url' => 'https://example.com', 'headers' => [], 'events' => [], 'foo' => 'bar']));
    }

    public function testIsLocalhostIp(): void
    {
        self::assertTrue(Webhooks::isLocalhostIp('localhost'));
        self::assertTrue(Webhooks::isLocalhostIp('127.0.0.1'));
        self::assertTrue(Webhooks::isLocalhostIp('[::1]'));
        self::assertFalse(Webhooks::isLocalhostIp('93.184.216.34'));
    }
}
