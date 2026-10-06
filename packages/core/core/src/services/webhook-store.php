<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Database\Database;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of packages/core/core/src/services/webhook-store.ts: webhook storage in `strapi_webhooks`.
 *
 * @phpstan-type Webhook array{id?: string, name: string, url: string, headers: array<string, string>, events: list<string>, isEnabled: bool}
 */
final class WebhookStore
{
    /** @var array<string, string> */
    public array $allowedEvents = [
        'ENTRY_CREATE' => 'entry.create',
        'ENTRY_UPDATE' => 'entry.update',
        'ENTRY_DELETE' => 'entry.delete',
        'ENTRY_PUBLISH' => 'entry.publish',
        'ENTRY_UNPUBLISH' => 'entry.unpublish',
        'ENTRY_DRAFT_DISCARD' => 'entry.draft-discard',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function createWebhookStore(Database $db): self
    {
        return new self($db);
    }

    /** @return array<string, mixed> the raw database model */
    public static function webhookModel(): array
    {
        return [
            'uid' => 'strapi::webhook',
            'singularName' => 'strapi_webhooks',
            'tableName' => 'strapi_webhooks',
            'attributes' => [
                'id' => ['type' => 'increments'],
                'name' => ['type' => 'string'],
                'url' => ['type' => 'text'],
                'headers' => ['type' => 'json'],
                'events' => ['type' => 'json'],
                'enabled' => ['type' => 'boolean'],
            ],
        ];
    }

    /** @param Webhook $data @return array<string, mixed> */
    private static function toDBObject(array $data): array
    {
        return [
            'name' => $data['name'],
            'url' => $data['url'],
            'headers' => $data['headers'] ?? [],
            'events' => $data['events'] ?? [],
            'enabled' => $data['isEnabled'] ?? true,
        ];
    }

    /** @param array<string, mixed> $row @return Webhook */
    private static function fromDBObject(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'url' => (string) $row['url'],
            'headers' => is_array($row['headers'] ?? null) ? $row['headers'] : [],
            'events' => is_array($row['events'] ?? null) ? array_values($row['events']) : [],
            'isEnabled' => (bool) ($row['enabled'] ?? false),
        ];
    }

    /** @param list<string> $events */
    private function webhookEventValidator(array $events): void
    {
        $allowedValues = array_values($this->allowedEvents);

        foreach ($events as $event) {
            if (!in_array($event, $allowedValues, true)) {
                throw new ValidationError("Webhook event {$event} is not supported");
            }
        }
    }

    public function addAllowedEvent(string $key, string $value): void
    {
        $this->allowedEvents[$key] = $value;
    }

    public function removeAllowedEvent(string $key): void
    {
        unset($this->allowedEvents[$key]);
    }

    /** @return list<string> */
    public function listAllowedEvents(): array
    {
        return array_keys($this->allowedEvents);
    }

    public function getAllowedEvent(string $key): ?string
    {
        return $this->allowedEvents[$key] ?? null;
    }

    /** @return list<Webhook> */
    public function findWebhooks(): array
    {
        return array_map(self::fromDBObject(...), $this->db->query('strapi::webhook')->findMany());
    }

    /** @return Webhook|null */
    public function findWebhook(string $id): ?array
    {
        $result = $this->db->query('strapi::webhook')->findOne(['where' => ['id' => $id]]);

        return $result !== null ? self::fromDBObject($result) : null;
    }

    /** @param Webhook $data @return Webhook */
    public function createWebhook(array $data): array
    {
        $this->webhookEventValidator($data['events'] ?? []);

        return self::fromDBObject($this->db->query('strapi::webhook')->create(['data' => self::toDBObject([...$data, 'isEnabled' => true])]));
    }

    /** @param Webhook $data @return Webhook|null */
    public function updateWebhook(string $id, array $data): ?array
    {
        $this->webhookEventValidator($data['events'] ?? []);

        $webhook = $this->db->query('strapi::webhook')->update(['where' => ['id' => $id], 'data' => self::toDBObject($data)]);

        return $webhook !== null ? self::fromDBObject($webhook) : null;
    }

    /** @return Webhook|null */
    public function deleteWebhook(string $id): ?array
    {
        $webhook = $this->db->query('strapi::webhook')->delete(['where' => ['id' => $id]]);

        return $webhook !== null ? self::fromDBObject($webhook) : null;
    }
}
