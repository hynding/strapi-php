<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * The `z` namespace object of `zod/v4` as static factories: `z.string()` is `Z::string()`.
 * {@see \Strapi\Utils\Zod} extends this class, so ported code imports it under the name `z`:
 *
 * ```php
 * use Strapi\Utils\Zod as z;
 *
 * $schema = z::object(['page' => z::coerce()->number()->int()->min(1)->optional()]);
 * ```
 *
 * Params (zod's last argument) are a message string or an array with `message` / `error`
 * (a string or `fn(array $issue): ?string`), and for refinements `path`, `abort`, `params`.
 */
class Z
{
    /** zod's `z.NEVER`, returned from a transform after `$ctx->addIssue()`. */
    public const Undefined NEVER = Undefined::Value;

    private static ?ZodRegistry $globalRegistry = null;

    // --- primitives ----------------------------------------------------------------------------

    /** @param string|array<string, mixed>|null $params */
    public static function string(string|array|null $params = null): ZodString
    {
        return new ZodString($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function number(string|array|null $params = null): ZodNumber
    {
        return new ZodNumber($params);
    }

    /**
     * `z.int()`: a safe integer.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function int(string|array|null $params = null): ZodNumber
    {
        return (new ZodNumber())->int($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function boolean(string|array|null $params = null): ZodBoolean
    {
        return new ZodBoolean($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function null(string|array|null $params = null): ZodNull
    {
        return new ZodNull($params);
    }

    public static function any(): ZodAny
    {
        return new ZodAny();
    }

    public static function unknown(): ZodUnknown
    {
        return new ZodUnknown();
    }

    /** @param string|array<string, mixed>|null $params */
    public static function never(string|array|null $params = null): ZodNever
    {
        return new ZodNever($params);
    }

    /**
     * `z.literal(value)` or `z.literal([a, b])`.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function literal(mixed $value, string|array|null $params = null): ZodLiteral
    {
        return new ZodLiteral($value, $params);
    }

    /**
     * @param list<string|int>|array<string, string|int>|class-string<\BackedEnum> $values
     * @param string|array<string, mixed>|null $params
     */
    public static function enum(array|string $values, string|array|null $params = null): ZodEnum
    {
        return new ZodEnum($values, $params);
    }

    /**
     * @param array<string, string|int>|class-string<\BackedEnum> $values
     * @param string|array<string, mixed>|null $params
     */
    public static function nativeEnum(array|string $values, string|array|null $params = null): ZodEnum
    {
        return new ZodEnum($values, $params);
    }

    // --- string formats ------------------------------------------------------------------------

    /** @param string|array<string, mixed>|null $params */
    public static function email(string|array|null $params = null): ZodString
    {
        return (new ZodString())->email($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function uuid(string|array|null $params = null): ZodString
    {
        return (new ZodString())->uuid($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function uuidv4(string|array|null $params = null): ZodString
    {
        return (new ZodString())->uuid(['version' => 'v4'] + (is_array($params) ? $params : ($params === null ? [] : ['message' => $params])));
    }

    /** @param string|array<string, mixed>|null $params */
    public static function guid(string|array|null $params = null): ZodString
    {
        return (new ZodString())->guid($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function url(string|array|null $params = null): ZodString
    {
        return (new ZodString())->url($params);
    }

    /**
     * `z.iso.datetime()`
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function datetime(string|array|null $params = null): ZodString
    {
        return (new ZodString())->datetime($params);
    }

    // --- composites ----------------------------------------------------------------------------

    /**
     * @param array<string, ZodType> $shape
     * @param string|array<string, mixed>|null $params
     */
    public static function object(array $shape = [], string|array|null $params = null): ZodObject
    {
        return new ZodObject($shape, $params);
    }

    /**
     * @param array<string, ZodType> $shape
     * @param string|array<string, mixed>|null $params
     */
    public static function strictObject(array $shape = [], string|array|null $params = null): ZodObject
    {
        return new ZodObject($shape, $params, new ZodNever());
    }

    /**
     * @param array<string, ZodType> $shape
     * @param string|array<string, mixed>|null $params
     */
    public static function looseObject(array $shape = [], string|array|null $params = null): ZodObject
    {
        return new ZodObject($shape, $params, new ZodUnknown());
    }

    /** @param string|array<string, mixed>|null $params */
    public static function array(ZodType $element, string|array|null $params = null): ZodArray
    {
        return new ZodArray($element, $params);
    }

    /**
     * @param list<ZodType> $items
     * @param string|array<string, mixed>|null $params
     */
    public static function tuple(array $items, ?ZodType $rest = null, string|array|null $params = null): ZodTuple
    {
        return new ZodTuple($items, $rest, $params);
    }

    /**
     * @param list<ZodType> $options
     * @param string|array<string, mixed>|null $params
     */
    public static function union(array $options, string|array|null $params = null): ZodUnion
    {
        return new ZodUnion($options, $params);
    }

    /**
     * @param list<ZodType> $options
     * @param string|array<string, mixed>|null $params
     */
    public static function discriminatedUnion(string $discriminator, array $options, string|array|null $params = null): ZodDiscriminatedUnion
    {
        return new ZodDiscriminatedUnion($discriminator, $options, $params);
    }

    public static function intersection(ZodType $left, ZodType $right): ZodIntersection
    {
        return new ZodIntersection($left, $right);
    }

    /**
     * `z.record(keySchema, valueSchema)`; `z.record(valueSchema)` keys by `z.string()`.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function record(ZodType $keyType, ?ZodType $valueType = null, string|array|null $params = null): ZodRecord
    {
        if ($valueType === null) {
            return new ZodRecord(new ZodString(), $keyType, $params);
        }

        return new ZodRecord($keyType, $valueType, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function partialRecord(ZodType $keyType, ZodType $valueType, string|array|null $params = null): ZodRecord
    {
        return new ZodRecord($keyType, $valueType, $params, partial: true);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function looseRecord(ZodType $keyType, ZodType $valueType, string|array|null $params = null): ZodRecord
    {
        return new ZodRecord($keyType, $valueType, $params, loose: true);
    }

    // --- wrappers & effects --------------------------------------------------------------------

    public static function optional(ZodType $schema): ZodOptional
    {
        return new ZodOptional($schema);
    }

    public static function nullable(ZodType $schema): ZodNullable
    {
        return new ZodNullable($schema);
    }

    /** @param \Closure(): ZodType $getter */
    public static function lazy(\Closure $getter): ZodLazy
    {
        return new ZodLazy($getter);
    }

    /**
     * `z.custom(fn, params)`; without `fn` every value passes.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function custom(?\Closure $fn = null, string|array|null $params = null): ZodCustom
    {
        return new ZodCustom($fn, $params);
    }

    /** `fn($value, ParsePayload $ctx)` */
    public static function transform(\Closure $fn): ZodTransform
    {
        return new ZodTransform($fn);
    }

    /** `z.preprocess(fn, schema)`: `fn($value, $ctx)` runs before `schema` parses. */
    public static function preprocess(\Closure $fn, ZodType $schema): ZodPipe
    {
        return new ZodPipe(new ZodTransform($fn), $schema);
    }

    public static function pipe(ZodType $in, ZodType $out): ZodPipe
    {
        return new ZodPipe($in, $out);
    }

    public static function coerce(): ZodCoerce
    {
        return new ZodCoerce();
    }

    // --- registries & JSON Schema --------------------------------------------------------------

    public static function registry(): ZodRegistry
    {
        return new ZodRegistry();
    }

    public static function globalRegistry(): ZodRegistry
    {
        return self::$globalRegistry ??= new ZodRegistry();
    }

    /**
     * @param array<string, mixed> $params see {@see ToJsonSchema}
     *
     * @return array<string, mixed>
     */
    public static function toJSONSchema(ZodType|ZodRegistry $schema, array $params = []): array
    {
        return ToJsonSchema::generate($schema, $params);
    }
}
