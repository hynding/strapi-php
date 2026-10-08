<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Metrics;

use Strapi\Core\Strapi;

/**
 * Port of services/mcp/metrics/metrics.ts: the MCP telemetry events. Sending goes through
 * `strapi.telemetry.send()` (a no-op in the PHP port, see services/metrics); errors are swallowed.
 */
final class Metrics
{
    /** Rate-limited via core telemetry `LIMITED_EVENTS`. */
    public const MCP_LIMITED_TELEMETRY_EVENTS = [
        'didStartMcpServer' => 'didStartMcpServer',
        'didUseMcpServer' => 'didUseMcpServer',
        'didNotAuthenticateMcpRequest' => 'didNotAuthenticateMcpRequest',
        'didNotHandleMcpRequest' => 'didNotHandleMcpRequest',
    ];

    private const ONE_DAY_MS = 24 * 60 * 60 * 1000;

    private static ?float $capabilityCacheExpiresAt = null;

    /** @var array<string, true> */
    private static array $executedCapabilities = [];

    /** @var array<string, true> */
    private static array $failedCapabilities = [];

    /** Resets in-memory capability metrics state (unit tests only). */
    public static function resetMcpMetricsStateForTests(): void
    {
        self::$executedCapabilities = [];
        self::$failedCapabilities = [];
        self::$capabilityCacheExpiresAt = self::now() + self::ONE_DAY_MS;
    }

    /** @param array{type: string, source: string, name: string} $identity */
    private static function capabilityCacheKey(array $identity, bool $succeeded): string
    {
        return ($succeeded ? 'execute' : 'notExecute') . ":{$identity['type']}:{$identity['source']}:{$identity['name']}";
    }

    /** @param array{type: string, source: string, name: string} $identity */
    private static function shouldSendCapabilityEvent(array $identity, bool $succeeded): bool
    {
        self::$capabilityCacheExpiresAt ??= self::now() + self::ONE_DAY_MS;
        if (self::now() > self::$capabilityCacheExpiresAt) {
            self::$executedCapabilities = [];
            self::$failedCapabilities = [];
            self::$capabilityCacheExpiresAt = self::now() + self::ONE_DAY_MS;
        }

        $key = self::capabilityCacheKey($identity, $succeeded);
        if ($succeeded) {
            if (isset(self::$executedCapabilities[$key])) {
                return false;
            }
            self::$executedCapabilities[$key] = true;
        } else {
            if (isset(self::$failedCapabilities[$key])) {
                return false;
            }
            self::$failedCapabilities[$key] = true;
        }

        return true;
    }

    /** @return 'timeout'|'error' */
    public static function classifyMcpRequestFailure(mixed $error): string
    {
        if ($error instanceof \Throwable && str_contains($error->getMessage(), 'timed out')) {
            return 'timeout';
        }

        return 'error';
    }

    /** @param array{path: string, numberOfTools: int, numberOfPrompts: int, numberOfResources: int} $properties */
    public static function sendDidStartMcpServer(Strapi $strapi, array $properties): void
    {
        self::send($strapi, self::MCP_LIMITED_TELEMETRY_EVENTS['didStartMcpServer'], [
            'eventProperties' => ['path' => $properties['path']],
            'groupProperties' => [
                'numberOfTools' => $properties['numberOfTools'],
                'numberOfPrompts' => $properties['numberOfPrompts'],
                'numberOfResources' => $properties['numberOfResources'],
            ],
        ]);
    }

    public static function sendDidUseMcpServer(Strapi $strapi): void
    {
        self::send($strapi, self::MCP_LIMITED_TELEMETRY_EVENTS['didUseMcpServer']);
    }

    /** @param 'missing_token'|'invalid_token' $errorClass */
    public static function sendDidNotAuthenticateMcpRequest(Strapi $strapi, string $errorClass): void
    {
        self::send($strapi, self::MCP_LIMITED_TELEMETRY_EVENTS['didNotAuthenticateMcpRequest'], ['eventProperties' => ['errorClass' => $errorClass]]);
    }

    /** @param 'timeout'|'error' $errorClass */
    public static function sendDidNotHandleMcpRequest(Strapi $strapi, string $errorClass): void
    {
        self::send($strapi, self::MCP_LIMITED_TELEMETRY_EVENTS['didNotHandleMcpRequest'], ['eventProperties' => ['errorClass' => $errorClass]]);
    }

    /** @param array{type: string, source: string, name: string} $identity */
    public static function sendDidExecuteMcpCapability(Strapi $strapi, array $identity): void
    {
        if (!self::shouldSendCapabilityEvent($identity, true)) {
            return;
        }

        self::send($strapi, 'didExecuteMcpCapability', [
            'eventProperties' => ['type' => $identity['type'], 'source' => $identity['source'], 'name' => $identity['name']],
        ]);
    }

    /** @param array{type: string, source: string, name: string} $identity */
    public static function sendDidNotExecuteMcpCapability(Strapi $strapi, array $identity, string $errorClass): void
    {
        if (!self::shouldSendCapabilityEvent($identity, false)) {
            return;
        }

        self::send($strapi, 'didNotExecuteMcpCapability', [
            'eventProperties' => ['type' => $identity['type'], 'source' => $identity['source'], 'name' => $identity['name'], 'errorClass' => $errorClass],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private static function send(Strapi $strapi, string $event, array $payload = []): void
    {
        try {
            $strapi->telemetry()->send($event, $payload);
        } catch (\Throwable) {
            // `.catch(() => {})`
        }
    }

    private static function now(): float
    {
        return microtime(true) * 1000;
    }
}
