<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Schemas;

use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/mcp/schemas/input-schemas.ts (the module constants are built on each call). */
final class InputSchemas
{
    public static function localeSchema(): ZodType
    {
        return z::string()->optional()->describe('Locale code (e.g. "en", "fr"). Defaults to the default locale.');
    }

    public static function statusSchema(): ZodType
    {
        return z::enum(['draft', 'published'])->optional()->describe('Document status. Defaults to "draft" when draftAndPublish is enabled.');
    }

    public static function documentIdSchema(): ZodType
    {
        return z::string()->min(1)->describe('Stable document ID (e.g. "z7v8zma53x01r6oceimv922b"). Use this as the canonical identifier across draft/published versions; numeric "id" can differ per version row.');
    }

    public static function pageSchema(): ZodType
    {
        return z::number()->int()->min(1)->optional()->describe('Page number (1-indexed, default: 1).');
    }

    public static function pageSizeSchema(): ZodType
    {
        return z::number()->int()->min(1)->max(100)->optional()->describe('Items per page (default: 25, max: 100).');
    }
}
