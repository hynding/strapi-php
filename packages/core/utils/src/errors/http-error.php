<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

/**
 * Generic HTTP error with a caller-provided status, standing in for the `http-errors`
 * package upstream re-exports from errors.ts. `name` is derived from the status (e.g. 404 → NotFoundError)
 * the way http-errors does, unless given explicitly.
 */
class HttpError extends ApplicationError
{
    public string $name = 'HttpError';

    private const NAMES = [
        400 => 'BadRequestError',
        401 => 'UnauthorizedError',
        402 => 'PaymentRequiredError',
        403 => 'ForbiddenError',
        404 => 'NotFoundError',
        405 => 'MethodNotAllowedError',
        406 => 'NotAcceptableError',
        408 => 'RequestTimeoutError',
        409 => 'ConflictError',
        410 => 'GoneError',
        413 => 'PayloadTooLargeError',
        415 => 'UnsupportedMediaTypeError',
        422 => 'UnprocessableEntityError',
        429 => 'TooManyRequestsError',
        500 => 'InternalServerError',
        501 => 'NotImplementedError',
        502 => 'BadGatewayError',
        503 => 'ServiceUnavailableError',
        504 => 'GatewayTimeoutError',
    ];

    /** @param array<string, mixed> $details */
    public function __construct(int $status, string $message = '', array $details = [], ?string $name = null, ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : self::statusText($status), $details, $previous);
        $this->status = $status;
        $this->name = $name ?? (self::NAMES[$status] ?? ($status >= 500 ? 'InternalServerError' : 'HttpError'));
    }

    public static function statusText(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Payload Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
