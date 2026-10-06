<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

/**
 * Base error for every Strapi error. Mirrors ApplicationError in packages/core/utils/src/errors.ts.
 *
 * Carries the same public shape upstream serializes into HTTP error bodies:
 * `{ status, name, message, details }`.
 */
class ApplicationError extends \RuntimeException
{
    public string $name = 'ApplicationError';

    public int $status = 400;

    /** @var array<string, mixed> */
    public array $details;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'An application error occurred', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->details = $details;
    }

    /** @return array{status: int, name: string, message: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'name' => $this->name,
            'message' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
