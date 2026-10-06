<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Middlewares;

use Strapi\Database\Errors\InvalidDateError;
use Strapi\Database\Errors\InvalidDateTimeError;
use Strapi\Database\Errors\InvalidRelationError;
use Strapi\Database\Errors\InvalidTimeError;
use Strapi\Utils\Errors\ValidationError;

/** Port of middlewares/errors.ts: turn database value errors into ValidationErrors. */
final class Errors
{
    /** @param array<string, mixed> $ctx */
    public static function databaseErrorsMiddleware(array $ctx, callable $next): mixed
    {
        try {
            return $next();
        } catch (InvalidTimeError | InvalidDateTimeError | InvalidDateError | InvalidRelationError $error) {
            throw new ValidationError($error->getMessage(), [], $error);
        }
    }
}
