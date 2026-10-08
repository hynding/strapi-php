<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Errors\YupValidationError;
use Strapi\Utils\Yup\Yup as YupSchema;
use Strapi\Utils\Yup\YupError;

/**
 * Port of packages/core/utils/src/validators.ts. Everything is synchronous here, so
 * `validateYupSchema` and `validateYupSchemaSync` behave the same.
 */
final class Validators
{
    private const array DEFAULT_VALIDATION_PARAM = ['strict' => true, 'abortEarly' => false];

    /** @throws YupValidationError */
    public static function handleYupError(YupError $error, ?string $errorMessage = null): never
    {
        throw new YupValidationError($error, $errorMessage);
    }

    /**
     * `validateYupSchema(schema, options)(body, errorMessage?)`: the validated (and, with
     * `strict: false`, cast) body, or a thrown {@see YupValidationError}.
     *
     * @param array<string, mixed> $options yup validate options; defaults `strict: true, abortEarly: false`
     * @return \Closure(mixed, string|null=): mixed
     */
    public static function validateYupSchema(YupSchema $schema, array $options = []): \Closure
    {
        return static function (mixed $body, ?string $errorMessage = null) use ($schema, $options): mixed {
            try {
                return $schema->validate($body, [...self::DEFAULT_VALIDATION_PARAM, ...$options]);
            } catch (YupError $e) {
                self::handleYupError($e, $errorMessage);
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return \Closure(mixed, string|null=): mixed
     */
    public static function validateYupSchemaSync(YupSchema $schema, array $options = []): \Closure
    {
        return static function (mixed $body, ?string $errorMessage = null) use ($schema, $options): mixed {
            try {
                return $schema->validateSync($body, [...self::DEFAULT_VALIDATION_PARAM, ...$options]);
            } catch (YupError $e) {
                self::handleYupError($e, $errorMessage);
            }
        };
    }
}
