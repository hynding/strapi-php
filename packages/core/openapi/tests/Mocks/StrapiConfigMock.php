<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Mocks;

use Strapi\Core\CoreApi\Routes\Validation\SchemaRegistry;
use Strapi\Openapi\Tests\Helpers\ContentApiSchemaRegistry;

/**
 * The `createStrapiMock(config)` helper of document-assemblers.test.ts / zod-to-openapi.test.ts:
 * `config.get(path)` reads a flat map, plus a content-API schema store.
 */
final class StrapiConfigMock
{
    public readonly SchemaRegistry $schemaRegistry;

    private readonly object $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], ?SchemaRegistry $schemaRegistry = null)
    {
        $this->schemaRegistry = $schemaRegistry ?? ContentApiSchemaRegistry::createTestContentAPISchemaRegistry();
        $this->config = new class ($config) {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function get(string $path, mixed $default = null): mixed
            {
                return $this->values[$path] ?? null;
            }
        };
    }

    public function config(): object
    {
        return $this->config;
    }

    public function contentAPISchemaRegistry(): SchemaRegistry
    {
        return $this->schemaRegistry;
    }
}
