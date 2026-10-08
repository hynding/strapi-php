<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Validation;

use Strapi\ContentManager\Validation\Zod;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/controllers/validation/relations.ts. */
final class Relations
{
    private static function validateFindAvailableSchema(): ZodType
    {
        return z::object([
            'component' => z::string()->optional(),
            'id' => Zod::strapiID()->optional(),
            '_q' => z::string()->optional(),
            'idsToOmit' => z::array(Zod::strapiID())->optional(),
            'idsToInclude' => z::array(Zod::strapiID())->optional(),
            'page' => z::coerce()->number()->int()->min(1)->optional(),
            'pageSize' => z::coerce()->number()->int()->min(1)->max(100)->optional(),
            'locale' => z::string()->nullable()->optional(),
            'status' => z::enum(['published', 'draft'])->nullable()->optional(),
        ]);
    }

    private static function validateFindExistingSchema(): ZodType
    {
        return z::object([
            'page' => z::coerce()->number()->int()->min(1)->optional(),
            'pageSize' => z::coerce()->number()->int()->min(1)->max(100)->optional(),
            'locale' => z::string()->nullable()->optional(),
            'status' => z::enum(['published', 'draft'])->nullable()->optional(),
        ]);
    }

    public static function validateFindAvailable(mixed $query, ?string $errorMessage = null): mixed
    {
        return Zod::validateZodAsync(self::validateFindAvailableSchema())($query, $errorMessage);
    }

    public static function validateFindExisting(mixed $query, ?string $errorMessage = null): mixed
    {
        return Zod::validateZodAsync(self::validateFindExistingSchema())($query, $errorMessage);
    }
}
