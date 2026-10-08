<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Yup\Reference;
use Strapi\Utils\Yup\StrapiIdSchema;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\Yup as MixedSchema;
use Strapi\Utils\Yup\YupArray;
use Strapi\Utils\Yup\YupBoolean;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupLazy;
use Strapi\Utils\Yup\YupNumber;
use Strapi\Utils\Yup\YupObject;
use Strapi\Utils\Yup\YupString;

/**
 * Port of packages/core/utils/src/yup.ts: the `yup` namespace @strapi/utils re-exports
 * (`import { yup } from '@strapi/utils'`), backed by the synchronous yup 0.32.9 port in `src/yup/`
 * with Strapi's extensions installed (`notNil`, `notNull`, `isFunction` on every schema,
 * `isCamelCase`/`isKebabCase` on strings, `onlyContainsFunctions` on objects, `uniqueProperty` on
 * arrays, `strapiID()` and the `notType` locale message).
 *
 * ```php
 * $schema = Yup::object([
 *     'name' => Yup::string()->min(1)->required(),
 *     'ids' => Yup::array()->of(Yup::strapiID())->min(1),
 * ])->noUnknown();
 * Validators::validateYupSchema($schema)($body); // throws YupValidationError
 * ```
 */
final class Yup
{
    public static function mixed(): MixedSchema
    {
        return new MixedSchema();
    }

    public static function string(): YupString
    {
        return new YupString();
    }

    public static function number(): YupNumber
    {
        return new YupNumber();
    }

    public static function boolean(): YupBoolean
    {
        return new YupBoolean();
    }

    public static function bool(): YupBoolean
    {
        return new YupBoolean();
    }

    public static function array(?MixedSchema $of = null): YupArray
    {
        return new YupArray($of);
    }

    /** @param array<string, MixedSchema|null> $shape */
    public static function object(array $shape = []): YupObject
    {
        return new YupObject($shape);
    }

    /** @param callable(mixed $value, array<string, mixed> $options): MixedSchema $builder */
    public static function lazy(callable $builder): YupLazy
    {
        return new YupLazy($builder);
    }

    /** yup.ts: `strapiID()` — a string or a non-negative integer. */
    public static function strapiID(): StrapiIdSchema
    {
        return new StrapiIdSchema();
    }

    /** @param array{map?: callable(mixed): mixed} $options */
    public static function ref(string $key, array $options = []): Reference
    {
        return new Reference($key, $options);
    }

    public static function isSchema(mixed $value): bool
    {
        return $value instanceof MixedSchema;
    }

    /** JS `undefined`, for values passed to `validate()` and compared in tests. */
    public static function undefined(): Undefined
    {
        return Undefined::value();
    }

    /** yup's `ValidationError.isError`. */
    public static function isValidationError(mixed $error): bool
    {
        return $error instanceof YupError;
    }
}
