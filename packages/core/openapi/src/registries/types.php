<?php

declare(strict_types=1);

namespace Strapi\Openapi\Registries;

/**
 * Port of packages/core/openapi/src/registries/types.ts, plus the registries object
 * `RegistriesFactory::createAll()` returns (`{ extractedComponentSchemas: {} }` upstream), shared by
 * reference between a document context and its sub-contexts.
 *
 * @phpstan-type ComponentType 'schemas'|'responses'|'parameters'|'examples'|'requestBodies'|'headers'|'securitySchemes'|'links'|'callbacks'|'pathItems'
 */
final class Registries
{
    /** @var \ArrayObject<string, array<string, mixed>|\stdClass> */
    public readonly \ArrayObject $extractedComponentSchemas;

    public function __construct()
    {
        $this->extractedComponentSchemas = new \ArrayObject();
    }
}
