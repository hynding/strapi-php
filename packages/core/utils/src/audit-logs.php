<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/audit-logs.ts.
 *
 * Upstream types the Strapi instance structurally (`AuditEmitter`) so this package does not
 * depend on `@strapi/types`/core. Likewise here `$strapi` is any object exposing, as methods
 * (strapi-php's `Strapi::eventHub()` / `Strapi::log()`) or as properties (upstream's shape):
 *
 * - `eventHub` with `emit(string $event, mixed ...$args)`;
 * - `log` with `error(string $message, array $context)` (PSR-3).
 *
 * Upstream's `emitAudit({ strapi }, event, payload)` becomes `AuditLogs::emitAudit($strapi, ...)`.
 */
final class AuditLogs
{
    /**
     * Emits an audit event and waits for it to be processed. A failed audit write, or a failing
     * listener, is logged here and doesn't affect the operation that emitted the event.
     */
    public static function emitAudit(object $strapi, string $event, mixed $payload): void
    {
        try {
            $eventHub = self::member($strapi, 'eventHub');
            $eventHub->emit($event, $payload);
        } catch (\Throwable $error) {
            self::member($strapi, 'log')->error("An event listener failed while handling {$event}", ['error' => $error]);
        }
    }

    /** Reads `$strapi->name()` or `$strapi->name`. */
    private static function member(object $strapi, string $name): mixed
    {
        $getter = [$strapi, $name];
        if (is_callable($getter)) {
            return $getter();
        }

        return $strapi->{$name};
    }
}
