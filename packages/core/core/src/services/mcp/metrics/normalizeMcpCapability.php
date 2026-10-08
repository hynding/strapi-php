<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Metrics;

/**
 * Port of services/mcp/metrics/normalizeMcpCapability.ts.
 *
 * @phpstan-type McpCapabilityType 'tool'|'prompt'|'resource'
 * @phpstan-type McpCapabilityIdentity array{type: string, source: string, name: string}
 */
final class NormalizeMcpCapability
{
    /**
     * @param array{source?: string|null, name?: string|null}|null $telemetry
     * @return array{type: string, source: string, name: string}
     */
    public static function normalizeMcpCapability(string $type, string $rawName, ?array $telemetry = null): array
    {
        return [
            'type' => $type,
            'source' => $telemetry['source'] ?? 'unknown',
            'name' => $telemetry['name'] ?? $rawName,
        ];
    }
}
