<?php

declare(strict_types=1);

namespace Strapi\Admin\AuditLogs;

use Strapi\Utils\AuditLogs;

/**
 * Port of server/src/audit-logs/admin-users.ts: the admin-user audit events, built from the
 * `admin::user` rows. `registerAdminUserAuditEvents` (used by the EE audit-logs lifecycle
 * service, not ported) is kept for parity.
 */
final class AdminUsers
{
    public const AUDITED_EVENTS = [
        'USER_CREATE' => 'admin-user.create',
        'USER_UPDATE' => 'admin-user.update',
        'USER_DELETE' => 'admin-user.delete',
        'PASSWORD_RESET_CREATE' => 'admin-user.password-reset.create',
        'PASSWORD_RESET_CONFIRM' => 'admin-user.password-reset.confirm',
        'PASSWORD_UPDATE' => 'admin-user.password.update',
        'INVITE_ACCEPT' => 'admin-user.invite.accept',
    ];

    /**
     * Event hub events kept for custom listeners.
     *
     * @deprecated Removed in the next major. Listen to the audit log events, or to the
     * database lifecycles, instead.
     */
    public const LEGACY_USER_EVENTS = [
        'CREATE' => 'user.create',
        'UPDATE' => 'user.update',
        'DELETE' => 'user.delete',
    ];

    /**
     * The fields an audit reader needs to identify the account and its access. Never
     * `password`, the tokens, or the timestamps: the row has its own date.
     */
    public const ADMIN_USER_TRACKED_FIELDS = [
        'firstname',
        'lastname',
        'email',
        'username',
        'preferedLanguage',
        'isActive',
    ];

    /**
     * Sorted, so order alone is not a change.
     *
     * @param array<mixed>|null $roles rows or ids
     * @return list<mixed>
     */
    public static function toRoleIds(?array $roles): array
    {
        $ids = array_map(static fn (mixed $role): mixed => is_array($role) ? ($role['id'] ?? null) : $role, array_values($roles ?? []));
        usort($ids, static fn (mixed $a, mixed $b): int => strcmp((string) $a, (string) $b));

        return $ids;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public static function getAdminUserChanges(array $previous, array $next): array
    {
        $changes = [];

        foreach (self::ADMIN_USER_TRACKED_FIELDS as $field) {
            // isActive is a boolean column that older rows may hold as null
            $before = $field === 'isActive' ? ($previous['isActive'] ?? null) === true : ($previous[$field] ?? null);
            $after = $field === 'isActive' ? ($next['isActive'] ?? null) === true : ($next[$field] ?? null);

            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        if (($previous['roles'] ?? null) !== null || ($next['roles'] ?? null) !== null) {
            $before = self::toRoleIds(is_array($previous['roles'] ?? null) ? $previous['roles'] : null);
            $after = self::toRoleIds(is_array($next['roles'] ?? null) ? $next['roles'] : null);

            if ($before != $after) {
                $changes['roles'] = ['before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }

    /**
     * `email` is required by the admin::user schema.
     *
     * @param array<string, mixed> $user
     * @return array{userId: mixed, email: mixed}
     */
    public static function toAdminUserEvent(array $user): array
    {
        return [
            'userId' => $user['id'] ?? null,
            'email' => $user['email'] ?? null,
        ];
    }

    /** @param array<string, mixed> $user */
    public static function emitAdminUserCreated(object $strapi, array $user): void
    {
        AuditLogs::emitAudit($strapi, self::AUDITED_EVENTS['USER_CREATE'], [
            ...self::toAdminUserEvent($user),
            'firstname' => $user['firstname'] ?? null,
            'lastname' => $user['lastname'] ?? null,
            'roles' => self::toRoleIds(is_array($user['roles'] ?? null) ? $user['roles'] : null),
            'isActive' => ($user['isActive'] ?? null) === true,
        ]);
    }

    /** @param array<string, mixed> $user */
    public static function emitAdminUserDeleted(object $strapi, array $user): void
    {
        AuditLogs::emitAudit($strapi, self::AUDITED_EVENTS['USER_DELETE'], self::toAdminUserEvent($user));
    }

    /**
     * Whether an update touches a field the audit log compares, so the previous row is needed.
     *
     * @param array<string, mixed> $attributes
     */
    public static function touchesTrackedFields(array $attributes): bool
    {
        foreach ([...self::ADMIN_USER_TRACKED_FIELDS, 'roles'] as $field) {
            if (array_key_exists($field, $attributes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The account events an update can produce, emitted by updateById after the write.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $updated
     * @param array<string, mixed> $attributes
     */
    public static function emitAdminUserUpdateAudits(object $strapi, ?array $previous, ?array $updated, array $attributes): void
    {
        if ($updated === null) {
            return;
        }

        if ($previous !== null) {
            $changes = self::getAdminUserChanges($previous, $updated);

            if ($changes !== []) {
                AuditLogs::emitAudit($strapi, self::AUDITED_EVENTS['USER_UPDATE'], [
                    ...self::toAdminUserEvent($updated),
                    'changes' => $changes,
                ]);
            }
        }

        if (array_key_exists('password', $attributes)) {
            AuditLogs::emitAudit($strapi, self::AUDITED_EVENTS['PASSWORD_UPDATE'], self::toAdminUserEvent($updated));
        }
    }

    /**
     * @param object $auditLogsLifecycle exposes `registerEvent(string $name, callable $transform, array $options = [])`
     */
    public static function registerAdminUserAuditEvents(object $auditLogsLifecycle): void
    {
        $resource = static fn (array $event): array => [
            'type' => 'admin-user',
            'id' => $event['userId'] ?? null,
            'email' => $event['email'] ?? null,
        ];

        $withoutSession = ['allowUnknownActor' => true];
        $register = [$auditLogsLifecycle, 'registerEvent'];
        if (!is_callable($register)) {
            throw new \InvalidArgumentException('auditLogsLifecycle must expose registerEvent()');
        }

        // The first super admin registers from a public form too
        $register(self::AUDITED_EVENTS['USER_CREATE'], static fn (array $event): array => [
            'resource' => $resource($event),
            'details' => [
                'email' => $event['email'] ?? null,
                'firstname' => $event['firstname'] ?? null,
                'lastname' => $event['lastname'] ?? null,
                'roles' => $event['roles'] ?? [],
                'isActive' => $event['isActive'] ?? false,
            ],
        ], $withoutSession);

        $register(self::AUDITED_EVENTS['USER_UPDATE'], static fn (array $event): array => [
            'resource' => $resource($event),
            'details' => ['changes' => $event['changes'] ?? []],
        ]);

        $register(self::AUDITED_EVENTS['USER_DELETE'], static fn (array $event): array => ['resource' => $resource($event)]);

        $register(self::AUDITED_EVENTS['PASSWORD_RESET_CREATE'], static fn (array $event): array => [
            'resource' => $resource($event),
            'details' => ['expiresAt' => \Strapi\Utils\Sessions::toISOString($event['expiresAt'])],
        ], $withoutSession);

        $register(self::AUDITED_EVENTS['PASSWORD_RESET_CONFIRM'], static fn (array $event): array => ['resource' => $resource($event)], $withoutSession);

        $register(self::AUDITED_EVENTS['INVITE_ACCEPT'], static fn (array $event): array => ['resource' => $resource($event)], $withoutSession);

        $register(self::AUDITED_EVENTS['PASSWORD_UPDATE'], static fn (array $event): array => ['resource' => $resource($event)]);
    }
}
