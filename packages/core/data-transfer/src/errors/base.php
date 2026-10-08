<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Errors;

/**
 * Port of src/errors/base.ts. Upstream extends the JS `Error` (whose `name` is `"Error"`): the
 * remote protocol sends that name as the error `code`, so `$name` keeps it.
 */
class DataTransferError extends \RuntimeException
{
    public string $name = 'Error';

    public string $origin;

    /** one of {@see Constants} `fatal` | `error` | `silly` */
    public string $severity;

    /** @var mixed upstream `details: T | null` */
    public mixed $details;

    public function __construct(string $origin, string $severity, ?string $message = null, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? '', 0, $previous);

        $this->origin = $origin;
        $this->severity = $severity;
        $this->details = $details;
    }
}
