<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Validation;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodUnion;

/**
 * Port of server/src/controllers/validation/schema.ts: the homepage layout schemas.
 *
 * @phpstan-type HomepageLayout array{version: int, widgets: list<array{uid: string, width: int}>, updatedAt: string}
 */
final class Schema
{
    // widths must be one of 4, 6, 8, 12
    public static function widthSchema(): ZodUnion
    {
        return z::union([z::literal(4), z::literal(6), z::literal(8), z::literal(12)]);
    }

    private static function widgetEntrySchema(): ZodObject
    {
        return z::object([
            'uid' => z::string()->nonempty(),
            'width' => self::widthSchema(),
        ])->strict();
    }

    public static function homepageLayoutSchema(): ZodObject
    {
        return z::object([
            'version' => z::number()->int()->min(1),
            'widgets' => z::array(self::widgetEntrySchema())->max(100),
            'updatedAt' => z::string()->datetime(),
        ])->strict();
    }

    public static function homepageLayoutWriteSchema(): ZodObject
    {
        return z::object([
            'version' => z::number()->int()->min(1)->optional(),
            'widgets' => z::array(self::widgetEntrySchema())->max(100),
            'updatedAt' => z::string()->datetime()->optional(),
        ])->strict();
    }
}
