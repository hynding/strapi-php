<?php

declare(strict_types=1);

namespace Strapi\Admin\AuditLogs;

use Strapi\Utils\Sessions;

/**
 * Port of server/src/audit-logs/tokens.ts: the token audit events (content-api, admin and
 * transfer tokens). `registerTokenAuditEvents` (used by the EE audit-logs lifecycle service, not
 * ported) is kept for parity.
 *
 * A token permission as recorded in the audit log is the action string (content-api and transfer
 * tokens) or `{ action, subject, properties }` (admin tokens): the row id says nothing to an
 * auditor, and conditions are copied from the owner's role and cannot be set per token.
 */
final class Tokens
{
    public const AUDITED_EVENTS = [
        'TOKEN_CREATE' => 'token.create',
        'TOKEN_UPDATE' => 'token.update',
        'TOKEN_DELETE' => 'token.delete',
        'TOKEN_REGENERATE' => 'token.regenerate',
    ];

    public const TOKEN_EDITABLE_FIELDS = ['name', 'description', 'type'];

    /**
     * @param array<mixed>|null $permissions rows of `{ action, subject, properties }`
     * @return list<array{action: mixed, subject: mixed, properties: mixed}>
     */
    public static function toAdminPermissionRefs(?array $permissions): array
    {
        return array_map(static fn (mixed $permission): array => [
            'action' => is_array($permission) ? ($permission['action'] ?? null) : null,
            'subject' => is_array($permission) ? ($permission['subject'] ?? null) : null,
            'properties' => is_array($permission) ? ($permission['properties'] ?? []) : [],
        ], array_values($permissions ?? []));
    }

    /**
     * @param array<mixed>|null $permissions action strings or rows with an `action`
     * @return list<mixed>
     */
    public static function toActionRefs(?array $permissions): array
    {
        return array_map(
            static fn (mixed $permission): mixed => is_string($permission) ? $permission : (is_array($permission) ? ($permission['action'] ?? null) : null),
            array_values($permissions ?? []),
        );
    }

    /**
     * Sorted: order alone does not count as a change.
     *
     * @param array<mixed> $refs
     * @return list<mixed>
     */
    private static function sortRefs(array $refs): array
    {
        $refs = array_values($refs);
        // lodash sortBy is stable; usort is stable since PHP 8
        usort($refs, static fn (mixed $a, mixed $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

        return $refs;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @return array<string, mixed>
     */
    public static function getTokenChanges(array $previous, array $next): array
    {
        $changes = [];

        foreach (self::TOKEN_EDITABLE_FIELDS as $field) {
            $before = $previous[$field] ?? null;
            $after = $next[$field] ?? null;

            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        if (array_key_exists('permissions', $previous) || array_key_exists('permissions', $next)) {
            $before = self::sortRefs(is_array($previous['permissions'] ?? null) ? $previous['permissions'] : []);
            $after = self::sortRefs(is_array($next['permissions'] ?? null) ? $next['permissions'] : []);

            if (json_encode($before) !== json_encode($after)) {
                $changes['permissions'] = ['before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }

    private static function toIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value) && !is_string($value)) {
            return Sessions::toISOString($value);
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return Sessions::toISOString((int) $value);
        }

        return $value instanceof \DateTimeInterface || is_string($value) ? Sessions::toISOString($value) : null;
    }

    /**
     * @param object $auditLogsLifecycle exposes `registerEvent(string $name, callable $transform)`
     */
    public static function registerTokenAuditEvents(object $auditLogsLifecycle): void
    {
        $register = [$auditLogsLifecycle, 'registerEvent'];
        if (!is_callable($register)) {
            throw new \InvalidArgumentException('auditLogsLifecycle must expose registerEvent()');
        }

        $tokenResource = static fn (array $event): array => [
            'type' => $event['kind'] ?? null,
            'id' => $event['tokenId'] ?? null,
            'name' => $event['name'] ?? null,
        ];

        $owner = static fn (array $event): array => ($event['adminUserOwner'] ?? null) !== null
            ? ['adminUserOwner' => $event['adminUserOwner']]
            : [];

        $register(self::AUDITED_EVENTS['TOKEN_CREATE'], static fn (array $event): array => [
            'resource' => $tokenResource($event),
            'details' => [
                'description' => $event['description'] ?? null,
                'lifespan' => ($event['lifespan'] ?? null) === null ? null : 0 + $event['lifespan'],
                'expiresAt' => self::toIso($event['expiresAt'] ?? null),
                ...(array_key_exists('type', $event) ? ['type' => $event['type']] : []),
                ...(array_key_exists('permissions', $event) ? ['permissions' => self::sortRefs(is_array($event['permissions']) ? $event['permissions'] : [])] : []),
                ...$owner($event),
            ],
        ]);

        $register(self::AUDITED_EVENTS['TOKEN_UPDATE'], static fn (array $event): array => [
            'resource' => $tokenResource($event),
            'details' => ['changes' => $event['changes'] ?? [], ...$owner($event)],
        ]);

        $withOwnerDetails = static fn (array $event): array => [
            'resource' => $tokenResource($event),
            ...(($event['adminUserOwner'] ?? null) !== null ? ['details' => ['adminUserOwner' => $event['adminUserOwner']]] : []),
        ];

        $register(self::AUDITED_EVENTS['TOKEN_DELETE'], $withOwnerDetails);
        $register(self::AUDITED_EVENTS['TOKEN_REGENERATE'], $withOwnerDetails);
    }
}
