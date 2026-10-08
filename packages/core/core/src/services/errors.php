<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\HttpError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\NotImplementedError;
use Strapi\Utils\Errors\PayloadTooLargeError;
use Strapi\Utils\Errors\RateLimitError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of packages/core/core/src/services/errors.ts: the HTTP error envelopes.
 *
 * @phpstan-type Envelope array{status: int, body: array{data: null, error: array{status: int, name: string, message: string, details: mixed}}}
 */
final class Errors
{
    private const ERRORS_AND_STATUS = [
        [UnauthorizedError::class, 401],
        [ForbiddenError::class, 403],
        [NotFoundError::class, 404],
        [PayloadTooLargeError::class, 413],
        [RateLimitError::class, 429],
        [NotImplementedError::class, 501],
    ];

    /** `details` is an object upstream (`{}` by default): an empty PHP array is sent as `{}`. */
    private static function details(mixed $details): mixed
    {
        return $details === [] ? new \stdClass() : $details;
    }

    /** @return Envelope */
    public static function formatApplicationError(ApplicationError $error): array
    {
        $status = 400;
        foreach (self::ERRORS_AND_STATUS as [$class, $code]) {
            if ($error instanceof $class) {
                $status = $code;
                break;
            }
        }

        return [
            'status' => $status,
            'body' => [
                'data' => null,
                'error' => ['status' => $status, 'name' => $error->name, 'message' => $error->getMessage(), 'details' => self::details($error->details)],
            ],
        ];
    }

    /** @return Envelope */
    public static function formatHttpError(HttpError $error): array
    {
        return [
            'status' => $error->status,
            'body' => [
                'data' => null,
                'error' => ['status' => $error->status, 'name' => $error->name, 'message' => $error->getMessage(), 'details' => self::details($error->details)],
            ],
        ];
    }

    /**
     * Unknown errors become a 500 whose message is hidden (`http-errors` only exposes 4xx messages).
     *
     * @return Envelope
     */
    public static function formatInternalError(mixed $error): array
    {
        if ($error instanceof HttpError) {
            return $error->status < 500 ? self::formatHttpError($error) : self::formatHttpError(new HttpError($error->status));
        }

        return self::formatHttpError(new HttpError(500));
    }
}
