<?php

declare(strict_types=1);

namespace Strapi\Openapi\Utils;

use Strapi\Core\CoreApi\Routes\Validation\SchemaRegistry;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/openapi/src/utils/zod.ts.
 *
 * JSON Schema values are plain arrays; an empty JSON object is a `\stdClass` (as
 * `Strapi\Utils\Zod\ToJsonSchema` produces it). The harvest bag shared by the assemblers and
 * `ComponentsWriter` (`extractedComponentSchemas`) is an `\ArrayObject`, a JS object reference.
 *
 * @phpstan-type SchemaObject array<string, mixed>|\stdClass
 * @phpstan-type ZodToOpenAPIOptions array{extractedComponentSchemas?: \ArrayObject<string, array<string, mixed>|\stdClass>}
 */
final class Zod
{
    /**
     * OpenAPI 3.1 uses the JSON Schema 2020-12 dialect. Zod 4.4.3 has no `openapi-3.1` target, so
     * `draft-2020-12` preserves valid 3.1 schemas. Explicit output mode preserves the conversion
     * direction used before these defaults were pinned.
     */
    public const OPENAPI_SCHEMA_CONVERSION_OPTIONS = [
        'target' => 'draft-2020-12',
        'io' => 'output',
    ];

    private const ZOD_SHARED_BUCKET = '__shared';

    /**
     * Zod 4.4.3 emits `#/components/schemas/__shared#/$defs/<id>` (and close variants) when an
     * identified nested schema is missing from the conversion registry.
     */
    private const ZOD_SHARED_REF_RE = '/^#\/components\/schemas\/__shared#?\/(?:\$defs|definitions)\/(.+)$/s';

    /**
     * Zod 4.4.3 embeds `$id` in registry `toJSONSchema` output when `uri` is configured. Strip it
     * so OpenAPI documents stay stable — inline schemas use random UUIDs as registry IDs.
     *
     * @param array<array-key, mixed>|\stdClass $schema
     *
     * @return array<array-key, mixed>|\stdClass
     */
    public static function stripJsonSchemaId(array|\stdClass $schema): array|\stdClass
    {
        if (is_array($schema)) {
            unset($schema['$id']);
        }

        return $schema;
    }

    /** Generates a path string for referencing a component schema by its identifier. */
    public static function toComponentsPath(string $id): string
    {
        return "#/components/schemas/{$id}";
    }

    private static function rewriteZodSharedRef(string $ref): string
    {
        if (preg_match(self::ZOD_SHARED_REF_RE, $ref, $match) !== 1) {
            return $ref;
        }

        return self::toComponentsPath($match[1]);
    }

    private static function rewriteZodSharedRefs(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            if ($key === '$ref' && is_string($nested)) {
                $value[$key] = self::rewriteZodSharedRef($nested);
                continue;
            }
            $value[$key] = self::rewriteZodSharedRefs($nested);
        }

        return $value;
    }

    /**
     * Moves Zod's sibling `__shared` bucket into named component schemas and rewrites `$ref`s
     * that targeted that bucket.
     *
     * Zod 4.4.3 emits `#/components/schemas/__shared#/$defs/<id>` when an identified nested
     * schema is not in the conversion registry. Those definitions must become first-class
     * `components.schemas` entries; `__shared` is never written out.
     *
     * @param array<string, mixed> $schemas modified in place
     *
     * @return array<string, array<string, mixed>|\stdClass> named schemas lifted from `__shared`
     *   (after `$id` stripping). Route conversion copies these into the shared harvest bag so
     *   ComponentsWriter can merge them into the document.
     */
    public static function liftZodSharedDefinitions(array &$schemas): array
    {
        $harvested = [];
        $shared = $schemas[self::ZOD_SHARED_BUCKET] ?? null;

        if (array_key_exists(self::ZOD_SHARED_BUCKET, $schemas)) {
            $defs = is_array($shared) ? ($shared['$defs'] ?? $shared['definitions'] ?? null) : null;

            if (is_array($defs)) {
                foreach ($defs as $id => $def) {
                    if (!is_array($def) && !$def instanceof \stdClass) {
                        continue;
                    }

                    $cleaned = self::stripJsonSchemaId($def);
                    $harvested[(string) $id] = $cleaned;

                    if (!array_key_exists((string) $id, $schemas)) {
                        $schemas[(string) $id] = $cleaned;
                    }
                }
            }

            unset($schemas[self::ZOD_SHARED_BUCKET]);
        }

        /** @var array<string, mixed> $schemas */
        $schemas = self::rewriteZodSharedRefs($schemas);
        /** @var array<string, array<string, mixed>|\stdClass> $harvested */
        $harvested = self::rewriteZodSharedRefs($harvested);

        foreach ($schemas as $key => $schema) {
            if (is_array($schema)) {
                $schemas[$key] = self::stripJsonSchemaId($schema);
            }
        }

        return $harvested;
    }

    /**
     * Converts a Zod schema to an OpenAPI Schema Object (v3.1).
     *
     * It uses a local registry to handle the conversion process and generates the appropriate
     * OpenAPI components. Identified nested schemas that Zod would otherwise park in `__shared`
     * are rewritten to `#/components/schemas/<id>` and copied into
     * `$options['extractedComponentSchemas']` when that bag is provided.
     *
     * @param SchemaRegistry $schemaStore the application-owned content-API schema store to copy
     *   named component definitions from. Conversion uses a local registry and does not read a
     *   live Zod registry from the store.
     * @param ZodToOpenAPIOptions $options
     *
     * @return array<array-key, mixed>|\stdClass
     */
    public static function zodToOpenAPI(ZodType $zodSchema, SchemaRegistry $schemaStore, array $options = []): array|\stdClass
    {
        try {
            $id = self::randomUUID();
            $registry = z::registry();

            // Add the schema to the local registry with a custom, unique ID
            $registry->add($zodSchema, ['id' => $id]);

            // Copy Strapi-owned definitions into the local registry so references resolve without
            // generating "__shared" definitions.
            $sameAs = null;
            foreach ($schemaStore->entries() as $key => $value) {
                if ($value === $zodSchema) {
                    // zod keeps both ids for one schema object; this registry keeps the last one
                    $sameAs = $key;
                }
                $registry->add($value, ['id' => $key]);
            }

            // Generate the schemas and only return the one we want, transform the URI path to be OpenAPI compliant
            $result = z::toJSONSchema($registry, [
                ...self::OPENAPI_SCHEMA_CONVERSION_OPTIONS,
                'uri' => static fn (string $id): string => self::toComponentsPath($id),
            ]);
            $schemas = is_array($result['schemas'] ?? null) ? $result['schemas'] : [];

            $harvested = self::liftZodSharedDefinitions($schemas);

            $bag = $options['extractedComponentSchemas'] ?? null;
            if ($bag instanceof \ArrayObject) {
                foreach ($harvested as $name => $schema) {
                    $bag[$name] = $schema;
                }
            }

            $schema = $schemas[$id] ?? ($sameAs !== null ? ($schemas[$sameAs] ?? null) : null);
            if (!is_array($schema) && !$schema instanceof \stdClass) {
                throw new \LogicException('missing converted schema');
            }

            // TODO: make sure it's compliant
            return self::stripJsonSchemaId($schema);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Couldn't transform the zod schema into an OpenAPI schema", 0, $e);
        }
    }

    /** `crypto.randomUUID()` */
    private static function randomUUID(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
