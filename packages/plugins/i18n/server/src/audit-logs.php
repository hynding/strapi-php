<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n;

use Strapi\Plugin\I18n\Constants\Constants;

/**
 * Port of server/src/audit-logs.ts.
 *
 * The code is what the rest of the system refers to a locale by, and it outlives the
 * row: the name can be edited, and the id resolves to nothing once it is deleted.
 */
final class AuditLogs
{
    /**
     * @param object $auditLogsLifecycle exposes `registerEvent(string $name, callable $transform)`
     *                                   (the part of the audit-logs lifecycle service used by this plugin)
     */
    public static function registerAuditEvents(object $auditLogsLifecycle): void
    {
        $register = [$auditLogsLifecycle, 'registerEvent'];
        if (!is_callable($register)) {
            throw new \InvalidArgumentException('auditLogsLifecycle must expose registerEvent()');
        }

        $localeResource = static fn (array $event): array => [
            'type' => 'locale',
            'id' => $event['localeId'] ?? null,
            'name' => $event['name'] ?? null,
            'code' => $event['code'] ?? null,
        ];

        $register(Constants::AUDITED_EVENTS['LOCALE_CREATE'], static fn (array $event): array => [
            'resource' => $localeResource($event),
            'details' => ['isDefault' => $event['isDefault'] ?? null],
        ]);

        $register(Constants::AUDITED_EVENTS['LOCALE_UPDATE'], static fn (array $event): array => [
            'resource' => $localeResource($event),
            'details' => ['changes' => $event['changes'] ?? null],
        ]);

        $register(Constants::AUDITED_EVENTS['LOCALE_DELETE'], static fn (array $event): array => [
            'resource' => $localeResource($event),
        ]);

        /*
         * The resource is the locale that became the default. `before` is null when no
         * default was set yet.
         */
        $register(Constants::AUDITED_EVENTS['LOCALE_DEFAULT_UPDATE'], static fn (array $event): array => [
            'resource' => $localeResource($event),
            'details' => ['changes' => $event['changes'] ?? null],
        ]);
    }
}
