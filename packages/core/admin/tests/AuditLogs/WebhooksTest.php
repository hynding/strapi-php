<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\AuditLogs;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\AuditLogs\Webhooks;

/** Port of server/src/audit-logs/__tests__/webhooks.test.ts. */
final class WebhooksTest extends TestCase
{
    private const WEBHOOK = [
        'id' => '4',
        'name' => 'Deploy site',
        'url' => 'https://example.com/hook',
        'headers' => ['X-Env' => 'prod', 'Authorization' => 'Bearer s3cret'],
        'events' => ['entry.update', 'entry.create'],
        'isEnabled' => true,
    ];

    /** @return array<string, callable> */
    private static function getTransformers(): array
    {
        $lifecycle = new class () {
            /** @var array<string, callable> */
            public array $transformers = [];

            public function registerEvent(string $name, callable $transform): void
            {
                $this->transformers[$name] = $transform;
            }
        };

        Webhooks::registerWebhookAuditEvents($lifecycle);

        return $lifecycle->transformers;
    }

    public function testRegistersTheThreeWebhookEvents(): void
    {
        $names = array_keys(self::getTransformers());
        sort($names);

        self::assertSame(['webhook.create', 'webhook.delete', 'webhook.update'], $names);
    }

    public function testWebhookCreateRecordsTheScopeWithHeaderNamesOnly(): void
    {
        $shape = self::getTransformers()['webhook.create'](Webhooks::toAuditedWebhook(self::WEBHOOK));

        self::assertSame([
            'resource' => ['type' => 'webhook', 'id' => '4', 'name' => 'Deploy site'],
            'details' => [
                'url' => 'https://example.com',
                'events' => ['entry.create', 'entry.update'],
                'headers' => ['Authorization', 'X-Env'],
                'isEnabled' => true,
            ],
        ], $shape);
        self::assertDoesNotMatchRegularExpression('/s3cret|prod/', (string) json_encode($shape));
    }

    public function testWebhookUpdateCarriesTheChanges(): void
    {
        $changes = ['url' => ['before' => 'https://a', 'after' => 'https://b']];

        self::assertSame([
            'resource' => ['type' => 'webhook', 'id' => '4', 'name' => 'Deploy site'],
            'details' => ['changes' => $changes],
        ], self::getTransformers()['webhook.update'](['webhookId' => '4', 'name' => 'Deploy site', 'changes' => $changes]));
    }

    public function testWebhookDeleteHasNoDetails(): void
    {
        self::assertSame(
            ['resource' => ['type' => 'webhook', 'id' => '4', 'name' => 'Deploy site']],
            self::getTransformers()['webhook.delete'](['webhookId' => '4', 'name' => 'Deploy site']),
        );
    }

    public function testToAuditedWebhookKeepsSchemeAndHostOfTheUrlOnly(): void
    {
        $audited = Webhooks::toAuditedWebhook([
            ...self::WEBHOOK,
            'url' => 'https://user:s3cretpw@hooks.example.com:8443/services/T1/B2/xyz?token=qt0ken#frag',
        ]);

        self::assertSame('https://hooks.example.com:8443', $audited['url']);
        self::assertDoesNotMatchRegularExpression('/s3cretpw|xyz|qt0ken|frag/', (string) json_encode($audited));
    }

    public function testToAuditedWebhookDropsHeaderValuesAndSortsEventsAndHeaderNames(): void
    {
        self::assertSame([
            'webhookId' => '4',
            'name' => 'Deploy site',
            'url' => 'https://example.com',
            'events' => ['entry.create', 'entry.update'],
            'headers' => ['Authorization', 'X-Env'],
            'isEnabled' => true,
        ], Webhooks::toAuditedWebhook(self::WEBHOOK));
    }

    public function testGetWebhookChangesReturnsAnEmptyObjectWhenOnlyTheOrderOfEventsOrHeadersDiffers(): void
    {
        self::assertSame([], Webhooks::getWebhookChanges(self::WEBHOOK, [
            ...self::WEBHOOK,
            'events' => ['entry.create', 'entry.update'],
            'headers' => ['Authorization' => 'Bearer s3cret', 'X-Env' => 'prod'],
        ]));
    }

    public function testGetWebhookChangesRecordsOnlyTheFlippedFlagWhenAFullSaveTogglesIsEnabled(): void
    {
        self::assertSame(
            ['isEnabled' => ['before' => true, 'after' => false]],
            Webhooks::getWebhookChanges(self::WEBHOOK, [...self::WEBHOOK, 'isEnabled' => false]),
        );
    }

    public function testGetWebhookChangesRecordsNameUrlEventsAndTheHeaderSetDiff(): void
    {
        self::assertSame([
            'name' => ['before' => 'Deploy site', 'after' => 'Deploy staging'],
            'url' => ['before' => 'https://example.com', 'after' => 'https://new.example.com'],
            'events' => ['before' => ['entry.create', 'entry.update'], 'after' => ['entry.create']],
            'headers' => ['added' => ['X-Region'], 'removed' => ['X-Env'], 'changed' => ['Authorization']],
        ], Webhooks::getWebhookChanges(self::WEBHOOK, [
            ...self::WEBHOOK,
            'name' => 'Deploy staging',
            'url' => 'https://new.example.com/hook',
            'events' => ['entry.create'],
            'headers' => ['Authorization' => 'Bearer other', 'X-Region' => 'eu'],
        ]));
    }

    public function testGetWebhookChangesRecordsAUrlChangeWithinTheSameHostWithoutThePath(): void
    {
        $changes = Webhooks::getWebhookChanges(
            [...self::WEBHOOK, 'url' => 'https://hooks.example.com/services/old-t0ken'],
            [...self::WEBHOOK, 'url' => 'https://hooks.example.com/services/new-t0ken'],
        );

        self::assertSame(['url' => ['before' => 'https://hooks.example.com', 'after' => 'https://hooks.example.com']], $changes);
        self::assertDoesNotMatchRegularExpression('/t0ken/', (string) json_encode($changes));
    }

    public function testGetWebhookChangesRecordsAHeaderWhoseValueChangedWithoutTheValue(): void
    {
        $changes = Webhooks::getWebhookChanges(self::WEBHOOK, [
            ...self::WEBHOOK,
            'headers' => [...self::WEBHOOK['headers'], 'Authorization' => 'Bearer rotated'],
        ]);

        self::assertSame(['headers' => ['added' => [], 'removed' => [], 'changed' => ['Authorization']]], $changes);
        self::assertDoesNotMatchRegularExpression('/s3cret|rotated/', (string) json_encode($changes));
    }

    public function testGetWebhookChangesClassifiesAHeaderNamedAfterAnObjectPrototypeMemberByOwnership(): void
    {
        $withHeader = [...self::WEBHOOK, 'headers' => [...self::WEBHOOK['headers'], 'constructor' => 'x']];

        self::assertSame(['headers' => ['added' => ['constructor'], 'removed' => [], 'changed' => []]], Webhooks::getWebhookChanges(self::WEBHOOK, $withHeader));
        self::assertSame(['headers' => ['added' => [], 'removed' => ['constructor'], 'changed' => []]], Webhooks::getWebhookChanges($withHeader, self::WEBHOOK));
    }
}
