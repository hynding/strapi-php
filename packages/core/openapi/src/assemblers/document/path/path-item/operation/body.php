<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Zod;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/body.ts.
 *
 * `contentAPI.addInputParams()` stores a body as `['shape' => [key => validator]]` in the PHP
 * port (upstream: `z.object(shape)`); it is converted as `z.object(shape)`, a validator that is not
 * a Zod schema (a plain PHP callable) standing as `z.unknown().optional()`. Other non-Zod bodies are skipped.
 */
final class BodyAssembler implements Assembler\Operation
{
    public function assemble(Context $context, array $route): void
    {
        $output = $context->output;
        $body = is_array($route['request'] ?? null) ? ($route['request']['body'] ?? null) : null;

        // If no `body` property is defined, we don't need to do anything
        if (!is_array($body) || $body === []) {
            return;
        }

        $content = [];

        $schemaStore = $context->strapi->contentAPISchemaRegistry();
        $extractedComponentSchemas = $context->registries->extractedComponentSchemas;

        foreach ($body as $media => $zodSchema) {
            $zodSchema = self::toZod($zodSchema);
            if ($zodSchema === null) {
                continue;
            }

            $content[(string) $media] = [
                'schema' => Zod::zodToOpenAPI($zodSchema, $schemaStore, ['extractedComponentSchemas' => $extractedComponentSchemas]),
            ];
        }

        if ($content === []) {
            return;
        }

        $output->data['requestBody'] = ['content' => $content];
    }

    private static function toZod(mixed $schema): ?ZodType
    {
        if ($schema instanceof ZodType) {
            return $schema;
        }

        if (is_array($schema) && is_array($schema['shape'] ?? null)) {
            $shape = [];
            foreach ($schema['shape'] as $key => $validator) {
                $shape[(string) $key] = $validator instanceof ZodType ? $validator : z::unknown()->optional();
            }

            return z::object($shape);
        }

        return null;
    }
}
