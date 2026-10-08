<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Routes\Validation;

use Strapi\Core\Strapi;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodObject;

/**
 * Port of server/src/routes/validation/locale.ts: a validator for i18n locale routes.
 * (`validation/index.ts` only re-exports it.)
 */
final class I18nLocaleRouteValidator
{
    public function __construct(protected readonly ?Strapi $strapi = null)
    {
    }

    /**
     * Generates a validation schema for a single locale.
     *
     * @return ZodObject A schema for validating locale objects
     */
    public function locale(): ZodObject
    {
        return z::object([
            'id' => z::number()->int()->positive(),
            'documentId' => z::string()->uuid(),
            'name' => z::string(),
            'code' => z::string()->length(2, 'Locale code must be exactly 2 characters'),
            'createdAt' => z::string(),
            'updatedAt' => z::string(),
            'publishedAt' => z::string()->nullable(),
            'isDefault' => z::boolean(),
        ]);
    }

    /**
     * Generates a validation schema for an array of locales
     *
     * @return ZodArray A schema for validating arrays of locales
     */
    public function locales(): ZodArray
    {
        return z::array($this->locale());
    }
}
