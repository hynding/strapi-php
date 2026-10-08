<?php

declare(strict_types=1);

namespace Strapi\Admin\AuditLogs;

/**
 * Port of server/src/audit-logs/webhooks.ts: the webhook audit events. Header values carry
 * `Authorization` tokens and are never recorded, not even on the event hub: only header names.
 * `registerWebhookAuditEvents` (used by the EE audit-logs lifecycle service, not ported) is kept
 * for parity.
 */
final class Webhooks
{
    public const AUDITED_EVENTS = [
        'WEBHOOK_CREATE' => 'webhook.create',
        'WEBHOOK_UPDATE' => 'webhook.update',
        'WEBHOOK_DELETE' => 'webhook.delete',
    ];

    private const AUDITED_FIELDS = ['name', 'url', 'events', 'isEnabled'];

    /**
     * `localeCompare` order.
     *
     * @param array<string> $values
     * @return list<string>
     */
    private static function sortStrings(array $values): array
    {
        $values = array_values($values);
        usort($values, static function (string $a, string $b): int {
            $cmp = strcasecmp($a, $b);

            return $cmp !== 0 ? $cmp : strcmp($b, $a);
        });

        return $values;
    }

    /**
     * Scheme and host only. The rest of a webhook URL is often the credential: userinfo,
     * `?token=`, or a path segment (Slack, Netlify, Discord hooks).
     */
    public static function toAuditedUrl(string $url): string
    {
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['scheme'], $parts['host']) && preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            $host = strtolower($parts['host']);
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $defaultPorts = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];
            $scheme = strtolower($parts['scheme']);
            if (isset($parts['port']) && ($defaultPorts[$scheme] ?? null) === $parts['port']) {
                $port = '';
            }

            return "{$scheme}://{$host}{$port}";
        }

        $stripped = (string) preg_replace('#//[^/@]*@#', '//', $url, 1);

        return preg_match('#^[^:]+://[^/?\#]*#', $stripped, $m) === 1 ? $m[0] : '';
    }

    /**
     * @param array<string, mixed> $webhook
     * @return array{webhookId: mixed, name: mixed, url: string, events: list<string>, headers: list<string>, isEnabled: mixed}
     */
    public static function toAuditedWebhook(array $webhook): array
    {
        $headers = is_array($webhook['headers'] ?? null) ? $webhook['headers'] : [];

        return [
            'webhookId' => $webhook['id'] ?? null,
            'name' => $webhook['name'] ?? null,
            'url' => self::toAuditedUrl((string) ($webhook['url'] ?? '')),
            'events' => self::sortStrings(array_map('strval', is_array($webhook['events'] ?? null) ? $webhook['events'] : [])),
            'headers' => self::sortStrings(array_map('strval', array_keys($headers))),
            'isEnabled' => $webhook['isEnabled'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $next
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}|null
     */
    private static function getHeaderChanges(?array $previous, ?array $next): ?array
    {
        $before = $previous ?? [];
        $after = $next ?? [];
        $names = self::sortStrings(array_values(array_unique(array_map('strval', [...array_keys($before), ...array_keys($after)]))));

        $changes = [
            'added' => array_values(array_filter($names, static fn (string $name): bool => !array_key_exists($name, $before))),
            'removed' => array_values(array_filter($names, static fn (string $name): bool => !array_key_exists($name, $after))),
            'changed' => array_values(array_filter(
                $names,
                static fn (string $name): bool => array_key_exists($name, $before) && array_key_exists($name, $after) && $before[$name] !== $after[$name],
            )),
        ];

        return $changes['added'] !== [] || $changes['removed'] !== [] || $changes['changed'] !== [] ? $changes : null;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @return array<string, mixed>
     */
    public static function getWebhookChanges(array $previous, array $next): array
    {
        $before = self::toAuditedWebhook($previous);
        $after = self::toAuditedWebhook($next);
        $changes = [];

        foreach (self::AUDITED_FIELDS as $field) {
            // The url is compared as stored, so a change to its stripped part is recorded too.
            $changed = $field === 'url'
                ? ($previous['url'] ?? null) !== ($next['url'] ?? null)
                : $before[$field] !== $after[$field];

            if ($changed) {
                $changes[$field] = ['before' => $before[$field], 'after' => $after[$field]];
            }
        }

        $headers = self::getHeaderChanges(
            is_array($previous['headers'] ?? null) ? $previous['headers'] : null,
            is_array($next['headers'] ?? null) ? $next['headers'] : null,
        );

        if ($headers !== null) {
            $changes['headers'] = $headers;
        }

        return $changes;
    }

    /**
     * @param object $auditLogsLifecycle exposes `registerEvent(string $name, callable $transform)`
     */
    public static function registerWebhookAuditEvents(object $auditLogsLifecycle): void
    {
        $register = [$auditLogsLifecycle, 'registerEvent'];
        if (!is_callable($register)) {
            throw new \InvalidArgumentException('auditLogsLifecycle must expose registerEvent()');
        }

        $webhookResource = static fn (array $event): array => [
            'type' => 'webhook',
            'id' => $event['webhookId'] ?? null,
            'name' => $event['name'] ?? null,
        ];

        $register(self::AUDITED_EVENTS['WEBHOOK_CREATE'], static fn (array $event): array => [
            'resource' => $webhookResource($event),
            'details' => [
                'url' => $event['url'] ?? null,
                'events' => $event['events'] ?? [],
                'headers' => $event['headers'] ?? [],
                'isEnabled' => $event['isEnabled'] ?? null,
            ],
        ]);

        $register(self::AUDITED_EVENTS['WEBHOOK_UPDATE'], static fn (array $event): array => [
            'resource' => $webhookResource($event),
            'details' => ['changes' => $event['changes'] ?? []],
        ]);

        $register(self::AUDITED_EVENTS['WEBHOOK_DELETE'], static fn (array $event): array => [
            'resource' => $webhookResource($event),
        ]);
    }
}
