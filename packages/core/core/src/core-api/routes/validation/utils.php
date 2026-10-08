<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Core\Strapi;
use Strapi\Core\Utils\Zod as ZodUtils;
use Strapi\Utils\Validation\Utilities;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodLazy;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/core-api/routes/validation/utils.ts. Schema generation happens
 * on-demand when schemas don't exist in the registry.
 *
 * `strapi` is typed structurally (upstream tests pass a partial mock): `log()` and
 * `contentAPISchemaRegistry()`.
 */
final class Utils
{
    /**
     * Safely adds or updates a schema in Strapi's owned OpenAPI registry. If a schema with the
     * given `id` already exists, it is removed before adding the new one (hot-reloading,
     * cyclical dependencies).
     *
     * @param Strapi $strapi
     */
    public static function safeGlobalRegistrySet(object $strapi, string $id, ZodType $schema): void
    {
        try {
            $transformedId = Utilities::transformUidToValidOpenApiName($id);

            $schemaStore = $strapi->contentAPISchemaRegistry();
            $isReplacing = $schemaStore->has($transformedId);

            $strapi->log()->debug(($isReplacing ? 'Replacing' : 'Registering') . " schema {$transformedId} in Strapi registry");
            $schemaStore->set($transformedId, $schema);
        } catch (\Throwable $error) {
            $strapi->log()->error("Schema registration failed: Failed to register schema {$id} in Strapi registry");

            throw $error;
        }
    }

    /**
     * Safely creates and registers a Zod schema in Strapi's owned OpenAPI registry, particularly
     * useful for handling cyclical data structures.
     *
     * If a schema with the given `id` already exists in the registry, it is returned. Otherwise a
     * temporary `z.any()` is registered, the callback creates the actual schema, which then replaces
     * the placeholder. Lookups of `id` while it is pending get a lazy stand-in, preventing infinite
     * loops.
     *
     * @param Strapi $strapi
     * @param \Closure(): ZodType $callback
     */
    public static function safeSchemaCreation(object $strapi, string $id, \Closure $callback): ZodType
    {
        try {
            $transformedId = Utilities::transformUidToValidOpenApiName($id);
            $schemaStore = $strapi->contentAPISchemaRegistry();

            // Return existing schema if already registered
            $existingSchema = $schemaStore->getOrDefer($transformedId);
            if ($existingSchema !== null) {
                return $existingSchema;
            }

            $strapi->log()->debug("Schema {$transformedId} not found in registry, generating new schema");

            // Determine if this is a built-in schema or user content
            $isBuiltInSchema = str_starts_with($id, 'plugin::') || str_starts_with($id, 'admin');

            if ($isBuiltInSchema) {
                $strapi->log()->debug("Initializing validation schema for {$transformedId}");
            } else {
                $strapi->log()->debug("📝 Generating validation schema for \"" . self::schemaName($transformedId) . '"');
            }

            $schemaStore->startPending($transformedId);

            try {
                // Temporary any placeholder before replacing with the actual schema type
                // Used to prevent infinite loops in cyclical data structures
                self::safeGlobalRegistrySet($strapi, $id, z::any());

                // Generate the actual schema using the callback
                $schema = $callback();

                // Replace the placeholder with the real schema
                self::safeGlobalRegistrySet($strapi, $id, $schema);

                // Show completion for user content only
                if (!$isBuiltInSchema) {
                    // reading the shape runs upstream's attribute getters
                    self::evaluateShape($schema);
                    $inspection = ZodUtils::inspectZodSchema($schema);
                    $fieldCount = $inspection['type'] === 'object' ? count($inspection['shape'] ?? []) : 0;
                    $strapi->log()->debug('   ✅ "' . self::schemaName($transformedId) . "\" schema created with {$fieldCount} fields");
                }

                return $schema;
            } finally {
                $schemaStore->finishPending($transformedId);
            }
        } catch (\Throwable $error) {
            $strapi->log()->error("Schema creation failed: Failed to create schema {$id}");

            throw $error;
        }
    }

    /** Builds the deferred attribute schemas of an object (upstream: the first read of its shape). */
    public static function evaluateShape(ZodType $schema): void
    {
        if (!$schema instanceof ZodObject) {
            return;
        }
        foreach ($schema->shape() as $field) {
            if ($field instanceof ZodLazy) {
                $field->unwrap();
            }
        }
    }

    private static function schemaName(string $transformedId): string
    {
        $name = preg_replace('/Document/', '', $transformedId, 1) ?? $transformedId;
        $name = preg_replace('/Entry/', '', $name, 1) ?? $name;

        return trim((string) preg_replace('/([A-Z])/', ' $1', $name));
    }
}
