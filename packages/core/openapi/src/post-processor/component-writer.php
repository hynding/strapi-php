<?php

declare(strict_types=1);

namespace Strapi\Openapi\PostProcessor;

use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Zod;
use Strapi\Utils\Zod as z;

/** Port of packages/core/openapi/src/post-processor/component-writer.ts. */
final class ComponentsWriter implements PostProcessor
{
    public function postProcess(Context $context): void
    {
        $output = $context->output;
        $registry = z::registry();

        foreach ($context->strapi->contentAPISchemaRegistry()->entries() as $id => $schema) {
            $registry->add($schema, ['id' => (string) $id]);
        }

        $result = z::toJSONSchema($registry, [
            ...Zod::OPENAPI_SCHEMA_CONVERSION_OPTIONS,
            'uri' => static fn (string $id): string => Zod::toComponentsPath($id),
        ]);

        $converted = is_array($result['schemas'] ?? null) ? $result['schemas'] : [];
        Zod::liftZodSharedDefinitions($converted);

        $existingComponents = is_array($output->data['components'] ?? null) ? $output->data['components'] : [];
        $extracted = $context->registries->extractedComponentSchemas->getArrayCopy();

        $schemas = [...$extracted, ...$converted];

        $output->data['components'] = [
            ...$existingComponents,
            'schemas' => $schemas === [] ? new \stdClass() : $schemas,
        ];
    }
}
