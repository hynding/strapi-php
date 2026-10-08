<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Core\Strapi;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/core-api/routes/validation/mappers.ts: maps Strapi attribute
 * definitions to Zod schemas.
 *
 * Upstream extends the object with getters, evaluated on the first access of the shape: right away
 * for a document (`.extend(attributesSchema.shape)`), after the schema is registered for a
 * component entry (`safeSchemaCreation` inspects it). `createAttributesSchema()` builds the
 * attribute schemas right away, in attribute order; `createLazyAttributesSchema()` defers each
 * one in a `z.lazy()` until {@see Utils::evaluateShape()} (or a conversion) reads it.
 */
final class Mappers
{
    /**
     * A Zod object schema for a collection of Strapi attributes.
     *
     * @param Strapi $strapi
     * @param array<string, array<string, mixed>> $attributes attribute name => attribute
     */
    public static function createAttributesSchema(object $strapi, array $attributes): ZodObject
    {
        $shape = [];
        foreach ($attributes as $name => $attribute) {
            $shape[(string) $name] = self::mapAttributeToSchema($strapi, $attribute);
        }

        return z::object([])->extend($shape);
    }

    /**
     * `createAttributesSchema()` with each attribute schema built on first access (upstream's getters).
     *
     * @param Strapi $strapi
     * @param array<string, array<string, mixed>> $attributes attribute name => attribute
     */
    public static function createLazyAttributesSchema(object $strapi, array $attributes): ZodObject
    {
        $shape = [];
        foreach ($attributes as $name => $attribute) {
            $shape[(string) $name] = z::lazy(static fn (): ZodType => self::mapAttributeToSchema($strapi, $attribute));
        }

        return z::object([])->extend($shape);
    }

    /**
     * A Zod input schema (validating incoming data) for a collection of Strapi attributes.
     *
     * @param Strapi $strapi
     * @param array<string, array<string, mixed>> $attributes attribute name => attribute
     */
    public static function createAttributesInputSchema(object $strapi, array $attributes): ZodObject
    {
        $shape = [];
        foreach ($attributes as $name => $attribute) {
            $shape[(string) $name] = self::mapAttributeToInputSchema($strapi, $attribute);
        }

        return z::object([])->extend($shape);
    }

    /**
     * Maps a Strapi attribute definition to a corresponding Zod validation schema.
     *
     * @param Strapi $strapi
     * @param array<string, mixed> $attribute
     */
    public static function mapAttributeToSchema(object $strapi, array $attribute): ZodType
    {
        $type = $attribute['type'] ?? null;

        return match ($type) {
            'biginteger' => Attributes::bigIntegerToSchema($attribute),
            'blocks' => Attributes::blocksToSchema(),
            'boolean' => Attributes::booleanToSchema($attribute),
            'component' => Attributes::componentToSchema($strapi, $attribute),
            'date' => Attributes::dateToSchema($attribute),
            'datetime' => Attributes::datetimeToSchema($attribute),
            'decimal' => Attributes::decimalToSchema($attribute),
            'dynamiczone' => Attributes::dynamicZoneToSchema($attribute),
            'email' => Attributes::emailToSchema($attribute),
            'enumeration' => Attributes::enumToSchema($attribute),
            'float' => Attributes::floatToSchema($attribute),
            'integer' => Attributes::integerToSchema($attribute),
            'json' => Attributes::jsonToSchema($attribute),
            'media' => Attributes::mediaToSchema($strapi, $attribute),
            'relation' => Attributes::relationToSchema($strapi, $attribute),
            'password', 'text', 'richtext', 'string' => Attributes::stringToSchema($attribute),
            'time' => Attributes::timeToSchema($attribute),
            'timestamp' => Attributes::timestampToSchema($attribute),
            'uid' => Attributes::uidToSchema($attribute),
            default => self::mapCustomField($strapi, $attribute, self::mapAttributeToSchema(...)),
        };
    }

    /**
     * Maps a Strapi attribute definition to a corresponding Zod input validation schema.
     *
     * @param Strapi $strapi
     * @param array<string, mixed> $attribute
     */
    public static function mapAttributeToInputSchema(object $strapi, array $attribute): ZodType
    {
        $type = $attribute['type'] ?? null;

        return match ($type) {
            'biginteger' => Attributes::bigIntegerToInputSchema($attribute),
            'blocks' => Attributes::blocksToInputSchema(),
            'boolean' => Attributes::booleanToInputSchema($attribute),
            'component' => Attributes::componentToInputSchema($attribute),
            'date' => Attributes::dateToInputSchema($attribute),
            'datetime' => Attributes::datetimeToInputSchema($attribute),
            'decimal' => Attributes::decimalToInputSchema($attribute),
            'dynamiczone' => Attributes::dynamicZoneToInputSchema($attribute),
            'email' => Attributes::emailToInputSchema($attribute),
            'enumeration' => Attributes::enumerationToInputSchema($attribute),
            'float' => Attributes::floatToInputSchema($attribute),
            'integer' => Attributes::integerToInputSchema($attribute),
            'json' => Attributes::jsonToInputSchema($attribute),
            'media' => Attributes::mediaToInputSchema($attribute),
            'relation' => Attributes::relationToInputSchema($attribute),
            'password', 'text', 'richtext', 'string' => Attributes::textToInputSchema($attribute),
            'time' => Attributes::timeToInputSchema($attribute),
            'timestamp' => Attributes::timestampToInputSchema($attribute),
            'uid' => Attributes::uidToInputSchema($attribute),
            default => self::mapCustomField($strapi, $attribute, self::mapAttributeToInputSchema(...)),
        };
    }

    /**
     * Re-dispatches a `customField` attribute with the resolved underlying Strapi kind.
     *
     * @param Strapi $strapi
     * @param array<string, mixed> $attribute
     * @param \Closure(Strapi, array<string, mixed>): ZodType $map
     */
    private static function mapCustomField(object $strapi, array $attribute, \Closure $map): ZodType
    {
        if (($attribute['type'] ?? null) === 'customField' && is_string($attribute['customField'] ?? null)) {
            $registry = $strapi->get('custom-fields');
            try {
                $customField = $registry instanceof \Strapi\Core\Registries\CustomFields ? $registry->get($attribute['customField']) : null;
            } catch (\Throwable) {
                $customField = null;
            }
            if (!is_array($customField)) {
                throw new \RuntimeException("Custom field '{$attribute['customField']}' not found");
            }

            // Re-dispatch with the resolved underlying Strapi kind
            return $map($strapi, [...$attribute, 'type' => $customField['type'] ?? null]);
        }

        $type = $attribute['type'] ?? null;

        throw new \RuntimeException('Unsupported attribute type: ' . (is_scalar($type) ? (string) $type : 'undefined'));
    }
}
