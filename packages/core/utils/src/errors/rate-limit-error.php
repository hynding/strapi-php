<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class RateLimitError extends ApplicationError
{
    public string $name = 'RateLimitError';

    public int $status = 429;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Too many requests, please try again later.', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
