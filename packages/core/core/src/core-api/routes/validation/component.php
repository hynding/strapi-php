<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Utils\Zod\ZodObject;

/**
 * Port of packages/core/core/src/core-api/routes/validation/component.ts: validation schemas for
 * a component's entries.
 */
final class CoreComponentRouteValidator extends AbstractCoreRouteValidator
{
    /** A validation schema for a single component entry: its scalar and populatable fields. */
    public function entry(): ZodObject
    {
        $entries = [...$this->scalarFields(), ...$this->populatableFields()];

        // upstream: the shape's getters run when it is first read (after registration)
        return Mappers::createLazyAttributesSchema($this->strapi, $entries);
    }
}
