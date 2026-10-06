<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class NotImplementedError extends ApplicationError
{
    public string $name = 'NotImplementedError';

    public int $status = 500;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'This feature is not implemented yet', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
