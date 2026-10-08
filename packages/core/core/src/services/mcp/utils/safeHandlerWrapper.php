<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Utils;

use Strapi\Core\Strapi;

/**
 * Port of services/mcp/utils/safeHandlerWrapper.ts: wraps an MCP capability handler to catch and
 * log errors from user-provided callbacks, so externally-registered capabilities (from plugin
 * developers) cannot crash Strapi core when they throw during execution.
 *
 * Errors are logged with full detail (message + stack) and returned to the MCP client as a safe
 * error response (no stack trace leak).
 */
final class SafeHandlerWrapper
{
    /**
     * @param callable $handler
     * @param array{strapi: Strapi, capabilityType: string, name: string, createErrorResult: callable(\Throwable, list<mixed>): mixed} $options
     * @return \Closure(mixed...): mixed
     */
    public static function wrapSafeHandler(callable $handler, array $options): \Closure
    {
        ['strapi' => $strapi, 'capabilityType' => $capabilityType, 'name' => $name, 'createErrorResult' => $createErrorResult] = $options;

        return static function (mixed ...$args) use ($handler, $strapi, $capabilityType, $name, $createErrorResult): mixed {
            try {
                return $handler(...$args);
            } catch (\Throwable $error) {
                $strapi->log()->error(
                    "[MCP] {$capabilityType} \"{$name}\" threw an error during execution: {$error->getMessage()}",
                    ['error' => $error->getMessage(), 'stack' => $error->getTraceAsString()],
                );

                return $createErrorResult($error, array_values($args));
            }
        };
    }
}
