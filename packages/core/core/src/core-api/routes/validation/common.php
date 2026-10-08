<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Validation\RouteValidators\AbstractRouteValidator;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodEnum;
use Strapi\Utils\Zod\ZodRecord;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/core-api/routes/validation/common.ts: the foundation for
 * validating core routes of a model, adding schema-aware validation for scalar and populatable
 * fields to `AbstractRouteValidator`. Upstream's getters are methods.
 *
 * `strapi` is typed structurally (upstream tests pass a partial mock with `getModel()`).
 */
abstract class AbstractCoreRouteValidator extends AbstractRouteValidator
{
    /**
     * @param Strapi $strapi the Strapi instance, used to read the loaded model
     * @param string $uid the content-type or component uid
     */
    public function __construct(protected readonly object $strapi, protected readonly string $uid)
    {
    }

    /** An enum schema of the scalar fields' keys. */
    public function scalarFieldsEnum(): ZodEnum
    {
        return z::enum(array_map('strval', array_keys($this->scalarFields())));
    }

    /** An enum schema of the populatable fields' keys (relations, components, files, etc.). */
    public function populatableFieldsEnum(): ZodEnum
    {
        return z::enum(array_map('strval', array_keys($this->populatableFields())));
    }

    /** An array of the scalar fields. */
    public function scalarFieldsArray(): ZodArray
    {
        return z::array($this->scalarFieldsEnum());
    }

    /** An array of the populatable fields. */
    public function populatableFieldsArray(): ZodArray
    {
        return z::array($this->populatableFieldsEnum());
    }

    /** The schema of the current model (`strapi.getModel(uid)`). */
    protected function schema(): Schema
    {
        return $this->strapi->getModel($this->uid) ?? throw new \RuntimeException("Model {$this->uid} not found");
    }

    /**
     * The scalar, non-private fields of the schema attributes.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function scalarFields(): array
    {
        $schema = $this->schema();

        return array_filter(
            $schema->attributes,
            static fn (array $attribute, string $attributeName): bool => ContentTypes::isScalarAttribute($attribute) && !ContentTypes::isPrivateAttribute($schema, $attributeName),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The populatable, non-private fields of the schema attributes.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function populatableFields(): array
    {
        $schema = $this->schema();

        return array_filter(
            $schema->attributes,
            static fn (array $attribute, string $attributeName): bool => !ContentTypes::isScalarAttribute($attribute) && !ContentTypes::isPrivateAttribute($schema, $attributeName),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** A sparse record with the scalar fields as keys and `$type` values. */
    public function fieldRecord(ZodType $type): ZodRecord
    {
        return z::partialRecord($this->scalarFieldsEnum(), $type);
    }
}
