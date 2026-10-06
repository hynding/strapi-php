<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class PayloadTooLargeError extends ApplicationError
{
    public string $name = 'PayloadTooLargeError';

    public int $status = 413;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Entity too large', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
