<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Utils;

use Strapi\Core\Services\Mcp\Sdk\RegisteredCapability;
use Strapi\Core\Strapi;

/**
 * Port of services/mcp/utils/createSafeCapabilityRegistration.ts.
 *
 * Creates a safe capability registration that protects Strapi core from user callback errors at
 * three levels:
 *
 * - Level 1: Catch factory invocation errors (createHandler throws)
 * - Level 2: Catch runtime execution errors (handler throws during invocation)
 * - Level 3: Catch MCP SDK registration errors (SDK rejects the registration)
 */
final class CreateSafeCapabilityRegistration
{
    private static ?RegisteredCapability $failed = null;

    /**
     * A no-op registered capability used as fallback when SDK registration fails: it stays
     * "disabled" and cannot be enabled (upstream's frozen `FAILED_REGISTERED_CAPABILITY`).
     */
    public static function failedRegisteredCapability(): RegisteredCapability
    {
        return self::$failed ??= new class () extends RegisteredCapability {
            public bool $enabled = false;

            public function enable(): void
            {
            }

            public function disable(): void
            {
            }

            public function remove(): void
            {
            }
        };
    }

    /**
     * @param array{
     *     strapi: Strapi,
     *     capabilityType: string,
     *     name: string,
     *     createHandler: callable(Strapi): callable,
     *     createFallbackHandler: callable(string): callable,
     *     createErrorResult: callable(\Throwable, list<mixed>): mixed,
     *     registerWithSdk: callable(\Closure): RegisteredCapability,
     * } $config
     */
    public static function createSafeCapabilityRegistration(array $config): RegisteredCapability
    {
        ['strapi' => $strapi, 'capabilityType' => $capabilityType, 'name' => $name] = $config;

        try {
            // Level 1: Safe factory invocation — catch errors from user's createHandler
            try {
                $rawHandler = ($config['createHandler'])($strapi);
            } catch (\Throwable $error) {
                $message = $error->getMessage();
                $strapi->log()->error("[MCP] {$capabilityType} \"{$name}\" handler factory threw during initialization: {$message}");

                // Substitute a fallback handler that always returns an error to the MCP client
                $rawHandler = ($config['createFallbackHandler'])($message);
            }

            // Level 2: Safe runtime wrapping — catch errors from user's handler during execution
            $safeHandler = SafeHandlerWrapper::wrapSafeHandler($rawHandler, [
                'strapi' => $strapi,
                'capabilityType' => $capabilityType,
                'name' => $name,
                'createErrorResult' => $config['createErrorResult'],
            ]);

            return ($config['registerWithSdk'])($safeHandler);
        } catch (\Throwable $error) {
            // Level 3: Catch MCP SDK registration errors — prevent one broken capability from aborting all others
            $message = $error->getMessage();
            $strapi->log()->error('[MCP] Failed to register ' . strtolower($capabilityType) . " \"{$name}\" with MCP server: {$message}");

            return self::failedRegisteredCapability();
        }
    }
}
