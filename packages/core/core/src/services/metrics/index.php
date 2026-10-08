<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Metrics;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/metrics/index.ts — a no-op: no telemetry is sent from
 * the PHP port. `send()` returns false like upstream does when telemetry is disabled.
 */
final class Metrics
{
    public function __construct(public readonly Strapi $strapi)
    {
    }

    public static function createTelemetry(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function register(): void
    {
    }

    public function bootstrap(): void
    {
    }

    public function destroy(): void
    {
    }

    public function isDisabled(): bool
    {
        return true;
    }

    /**
     * Upstream posts the event to Strapi's telemetry endpoint. The PHP port sends nothing: it
     * records the event at debug level and returns false, as upstream does when telemetry is off.
     *
     * @param array<string, mixed> $payload
     */
    public function send(string $event, array $payload = []): bool
    {
        $this->strapi->log()->debug("Telemetry is disabled: event {$event} was not sent");

        return false;
    }
}
