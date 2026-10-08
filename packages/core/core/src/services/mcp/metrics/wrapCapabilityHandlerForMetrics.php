<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Metrics;

use Strapi\Core\Strapi;

/** Port of services/mcp/metrics/wrapCapabilityHandlerForMetrics.ts. */
final class WrapCapabilityHandlerForMetrics
{
    private static function isCapabilityFailure(mixed $result): bool
    {
        return is_array($result) && ($result['isError'] ?? null) === true;
    }

    /**
     * @param array{source?: string|null, name?: string|null}|null $telemetry
     * @param callable $handler
     * @return \Closure(mixed...): mixed
     */
    public static function wrapCapabilityHandlerForMetrics(Strapi $strapi, string $type, string $capabilityName, ?array $telemetry, callable $handler): \Closure
    {
        return static function (mixed ...$args) use ($strapi, $type, $capabilityName, $telemetry, $handler): mixed {
            $result = $handler(...$args);
            $identity = NormalizeMcpCapability::normalizeMcpCapability($type, $capabilityName, $telemetry);

            if (self::isCapabilityFailure($result)) {
                Metrics::sendDidNotExecuteMcpCapability($strapi, $identity, 'execution_error');
            } else {
                Metrics::sendDidExecuteMcpCapability($strapi, $identity);
            }

            return $result;
        };
    }
}
