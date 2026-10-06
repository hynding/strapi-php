<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\HttpError;

/**
 * Facade over the error classes in src/errors/. Upstream keeps every class in errors.ts;
 * the one-class-per-file rule puts each in src/errors/<kebab-name>.php, and this class keeps
 * the module-level helpers.
 */
final class Errors
{
    /**
     * Wrap any throwable into an ApplicationError (unchanged when it already is one).
     * Unknown errors become a 500 HttpError with the original as `previous`.
     */
    public static function fromThrowable(\Throwable $error): ApplicationError
    {
        if ($error instanceof ApplicationError) {
            return $error;
        }

        return new HttpError(500, $error->getMessage() !== '' ? $error->getMessage() : 'Internal Server Error', [], 'InternalServerError', $error);
    }

    public static function isApplicationError(mixed $error): bool
    {
        return $error instanceof ApplicationError;
    }

    /**
     * Serialize an error into the upstream HTTP error envelope.
     *
     * @return array{status: int, body: array{data: null, error: array{status: int, name: string, message: string, details: array<string, mixed>}}}
     */
    public static function format(\Throwable $error): array
    {
        $appError = self::fromThrowable($error);

        return [
            'status' => $appError->status,
            'body' => ['data' => null, 'error' => $appError->toArray()],
        ];
    }
}
