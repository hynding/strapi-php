<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Utils;

/**
 * Port of services/mcp/utils/withTimeout.ts.
 *
 * Upstream races a promise against a timer. PHP runs the operation synchronously and cannot
 * interrupt it: the operation runs to completion, and when it took longer than `timeoutMs` the
 * same "timed out" error is thrown afterwards (so the caller logs and classifies it as upstream).
 * An error thrown by the operation is rethrown as is.
 */
final class WithTimeout
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function withTimeout(callable $operation, int|float $timeoutMs, string $operationName): mixed
    {
        $start = hrtime(true);
        $result = $operation();
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        if ($elapsedMs > $timeoutMs) {
            throw new \RuntimeException("Operation '{$operationName}' timed out after {$timeoutMs}ms");
        }

        return $result;
    }
}
