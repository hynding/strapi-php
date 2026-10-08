<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Core\Strapi;
use Strapi\Utils\Relations;
use Strapi\Utils\Validation\Utilities;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\Undefined;
use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodString;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/core-api/routes/validation/attributes.ts: converts Strapi schema
 * attributes into Zod schemas (primitive types, components, dynamic zones, media and relations),
 * plus the input schemas used to validate incoming data.
 *
 * An attribute property that is absent is `undefined` upstream (`Undefined::Value` here).
 */
final class Attributes
{
    /** @param array<string, mixed> $attribute */
    private static function prop(array $attribute, string $name): mixed
    {
        return array_key_exists($name, $attribute) ? $attribute[$name] : Undefined::Value;
    }

    /** @return \Closure(ZodType): ZodType */
    private static function stringMin(mixed $min): \Closure
    {
        return static fn (ZodType $schema): ZodType => $min !== Undefined::Value && $schema instanceof ZodString ? $schema->min((int) $min) : $schema;
    }

    /** @return \Closure(ZodType): ZodType */
    private static function stringMax(mixed $max): \Closure
    {
        return static fn (ZodType $schema): ZodType => $max !== Undefined::Value && $schema instanceof ZodString ? $schema->max((int) $max) : $schema;
    }

    /** @return \Closure(ZodType): ZodType */
    private static function arrayMin(mixed $min): \Closure
    {
        return static fn (ZodType $schema): ZodType => $min !== Undefined::Value && $min !== null && $schema instanceof ZodArray ? $schema->min((int) $min) : $schema;
    }

    /** @return \Closure(ZodType): ZodType */
    private static function arrayMax(mixed $max): \Closure
    {
        return static fn (ZodType $schema): ZodType => $max !== Undefined::Value && $max !== null && $schema instanceof ZodArray ? $schema->max((int) $max) : $schema;
    }

    /**
     * Converts a BigInteger attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function bigIntegerToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::string(), [
            self::stringMin(self::prop($attribute, 'min')),
            self::stringMax(self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A biginteger field');
    }

    /** Converts a blocks attribute to a Zod schema. */
    public static function blocksToSchema(): ZodType
    {
        return z::array(z::any())->describe('A blocks field');
    }

    /**
     * Converts a boolean attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function booleanToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::boolean()->nullable(), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A boolean field');
    }

    /**
     * Converts a component attribute to a Zod schema.
     *
     * @param Strapi $strapi
     * @param array<string, mixed> $attribute
     */
    public static function componentToSchema(object $strapi, array $attribute): ZodType
    {
        $component = (string) $attribute['component'];

        $componentSchema = Utils::safeSchemaCreation(
            $strapi,
            $component,
            static fn (): ZodType => (new CoreComponentRouteValidator($strapi, $component))->entry(),
        );

        $baseSchema = !empty($attribute['repeatable']) ? z::array($componentSchema) : $componentSchema;

        $schema = Utilities::augmentSchema($baseSchema, [
            self::arrayMin(self::prop($attribute, 'min')),
            self::arrayMax(self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A component field');
    }

    /**
     * Converts a date attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function dateToSchema(array $attribute): ZodType
    {
        return self::plainString($attribute, true)->describe('A date field');
    }

    /**
     * Converts a datetime attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function datetimeToSchema(array $attribute): ZodType
    {
        return self::plainString($attribute, true)->describe('A datetime field');
    }

    /**
     * Converts a decimal attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function decimalToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::number(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A decimal field');
    }

    /**
     * Converts a dynamic zone attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function dynamicZoneToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::array(z::any()), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A dynamic zone field');
    }

    /**
     * Converts an email attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function emailToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::email(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'minLength'), self::prop($attribute, 'maxLength')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('An email field');
    }

    /**
     * Converts an enumeration attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function enumToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::enum(self::enumValues($attribute)), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('An enum field');
    }

    /**
     * Converts a float attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function floatToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::number(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A float field');
    }

    /**
     * Converts an integer attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function integerToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::number()->int(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('An integer field');
    }

    /**
     * Converts a JSON attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function jsonToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::any(), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A JSON field');
    }

    /**
     * Converts a media attribute to a Zod schema.
     *
     * @param Strapi $strapi
     * @param array<string, mixed> $attribute
     */
    public static function mediaToSchema(object $strapi, array $attribute): ZodType
    {
        $fileSchema = $strapi->plugin('upload')->contentTypes()['file'] ?? null;
        $fileUid = $fileSchema !== null ? $fileSchema->uid : 'plugin::upload.file';

        $mediaSchema = Utils::safeSchemaCreation(
            $strapi,
            $fileUid,
            static fn (): ZodType => (new CoreContentTypeRouteValidator($strapi, $fileUid))->document(),
        );

        $baseSchema = !empty($attribute['multiple']) ? z::array($mediaSchema) : $mediaSchema;

        $schema = Utilities::augmentSchema($baseSchema, [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A media field');
    }

    /**
     * Converts a relation attribute to a Zod schema.
     *
     * @param Strapi $strapi
     * @param array<string, mixed> $attribute
     */
    public static function relationToSchema(object $strapi, array $attribute): ZodType
    {
        if (!array_key_exists('target', $attribute)) {
            return z::any();
        }

        $target = (string) $attribute['target'];

        $targetSchema = Utils::safeSchemaCreation(
            $strapi,
            $target,
            static fn (): ZodType => (new CoreContentTypeRouteValidator($strapi, $target))->document(),
        );

        $baseSchema = Relations::isAnyToMany($attribute) ? z::array($targetSchema) : $targetSchema;

        $schema = Utilities::augmentSchema($baseSchema, [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A relational field');
    }

    /**
     * Converts a string, text, rich text, or password attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function stringToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::string(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'minLength'), self::prop($attribute, 'maxLength')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A ' . (string) $attribute['type'] . ' field');
    }

    /**
     * Converts a time attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function timeToSchema(array $attribute): ZodType
    {
        return self::plainString($attribute, true)->describe('A time field');
    }

    /**
     * Converts a timestamp attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function timestampToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::union([z::string(), z::number()]), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A timestamp field');
    }

    /**
     * Converts a UID attribute to a Zod schema.
     *
     * @param array<string, mixed> $attribute
     */
    public static function uidToSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::string(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'minLength'), self::prop($attribute, 'maxLength')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
            Utilities::maybeReadonly(self::prop($attribute, 'writable')),
        ]);

        return $schema->describe('A UID field');
    }

    // --- input schemas ---------------------------------------------------------------------

    /**
     * Converts a BigInteger attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function bigIntegerToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::string(), [
            self::stringMin(self::prop($attribute, 'min')),
            self::stringMax(self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A biginteger field');
    }

    /** Converts a blocks attribute to a Zod schema for input validation. */
    public static function blocksToInputSchema(): ZodType
    {
        // TODO: better support blocks data structure
        return z::array(z::any())->describe('A blocks field');
    }

    /**
     * Converts a boolean attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function booleanToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::enum(Constants::BOOLEAN_LITERAL_VALUES)->nullable(), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A boolean field');
    }

    /**
     * Converts a component attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function componentToInputSchema(array $attribute): ZodType
    {
        $baseSchema = !empty($attribute['repeatable']) ? z::array(z::any()) : z::any();

        $schema = Utilities::augmentSchema($baseSchema, [
            self::arrayMin(self::prop($attribute, 'min')),
            self::arrayMax(self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
        ]);

        return $schema->describe('A component field');
    }

    /**
     * Converts a date attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function dateToInputSchema(array $attribute): ZodType
    {
        return self::plainString($attribute, false)->describe('A date field');
    }

    /**
     * Converts a datetime attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function datetimeToInputSchema(array $attribute): ZodType
    {
        return self::plainString($attribute, false)->describe('A datetime field');
    }

    /**
     * Converts a decimal attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function decimalToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::number(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A decimal field');
    }

    /**
     * Converts a dynamic zone attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function dynamicZoneToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::array(z::any()), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
        ]);

        return $schema->describe('A dynamic zone field');
    }

    /**
     * Converts an email attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function emailToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::email(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'minLength'), self::prop($attribute, 'maxLength')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('An email field');
    }

    /**
     * Converts an enumeration attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function enumerationToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::enum(self::enumValues($attribute)), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('An enum field');
    }

    /**
     * Converts a float attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function floatToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::number(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A float field');
    }

    /**
     * Converts an integer attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function integerToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::number()->int(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'min'), self::prop($attribute, 'max')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        // upstream describes integer inputs as 'A float field'
        return $schema->describe('A float field');
    }

    /**
     * Converts a JSON attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function jsonToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::any(), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A JSON field');
    }

    /**
     * Converts a media attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function mediaToInputSchema(array $attribute): ZodType
    {
        $baseSchema = !empty($attribute['multiple']) ? z::array(z::any()) : z::any();

        $schema = Utilities::augmentSchema($baseSchema, [Utilities::maybeRequired(self::prop($attribute, 'required'))]);

        return $schema->describe('A media field');
    }

    /**
     * Converts a relation attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function relationToInputSchema(array $attribute): ZodType
    {
        $isToMany = Relations::isAnyToMany($attribute);
        $uuid = z::string()->uuid();
        $baseSchema = $isToMany ? z::array($uuid) : $uuid;

        $schema = Utilities::augmentSchema($baseSchema, [Utilities::maybeRequired(self::prop($attribute, 'required'))]);

        return $schema->describe('A relational field');
    }

    /**
     * Converts a string, text, rich text, or password attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function textToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::string(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'minLength'), self::prop($attribute, 'maxLength')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A ' . (string) $attribute['type'] . ' field');
    }

    /**
     * Converts a time attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function timeToInputSchema(array $attribute): ZodType
    {
        return self::plainString($attribute, false)->describe('A time field');
    }

    /**
     * Converts a timestamp attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function timestampToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::union([z::string(), z::number()]), [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A timestamp field');
    }

    /**
     * Converts a UID attribute to a Zod schema for input validation.
     *
     * @param array<string, mixed> $attribute
     */
    public static function uidToInputSchema(array $attribute): ZodType
    {
        $schema = Utilities::augmentSchema(z::string(), [
            Utilities::maybeWithMinMax(self::prop($attribute, 'minLength'), self::prop($attribute, 'maxLength')),
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ]);

        return $schema->describe('A UID field');
    }

    // --- helpers ---------------------------------------------------------------------------

    /**
     * `augmentSchema(z.string(), [maybeRequired, maybeWithDefault, maybeReadonly?])` (date, datetime, time).
     *
     * @param array<string, mixed> $attribute
     */
    private static function plainString(array $attribute, bool $readonly): ZodType
    {
        $modifiers = [
            Utilities::maybeRequired(self::prop($attribute, 'required')),
            Utilities::maybeWithDefault(self::prop($attribute, 'default')),
        ];
        if ($readonly) {
            $modifiers[] = Utilities::maybeReadonly(self::prop($attribute, 'writable'));
        }

        return Utilities::augmentSchema(z::string(), $modifiers);
    }

    /**
     * @param array<string, mixed> $attribute
     *
     * @return list<string>
     */
    private static function enumValues(array $attribute): array
    {
        return array_values(array_map('strval', is_array($attribute['enum'] ?? null) ? $attribute['enum'] : []));
    }
}
