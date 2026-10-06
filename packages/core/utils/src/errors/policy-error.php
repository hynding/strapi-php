<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class PolicyError extends ForbiddenError
{
    public string $name = 'PolicyError';

    /** @param array<string, mixed> $details e.g. ['policy' => 'global::is-authenticated'] */
    public function __construct(string $message = 'Policy Failed', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
