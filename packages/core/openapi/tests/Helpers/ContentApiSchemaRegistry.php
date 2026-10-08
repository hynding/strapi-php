<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Helpers;

use Strapi\Core\CoreApi\Routes\Validation\SchemaRegistry;

/**
 * Port of __tests__/helpers/content-api-schema-registry.ts: a content-API schema store for the
 * tests (upstream builds a Map-backed stand-in; the core store is a plain class here).
 */
final class ContentApiSchemaRegistry
{
    public static function createTestContentAPISchemaRegistry(): SchemaRegistry
    {
        return SchemaRegistry::createContentAPISchemaRegistry();
    }
}
