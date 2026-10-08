<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine;

use Strapi\DataTransfer\Errors\DataTransferError;

/**
 * Port of src/engine/errors.ts: `TransferEngineError`. The step-specific subclasses live in
 * engine/errors/ (one class per file). `details` is `['step' => ..., 'details' => mixed]`.
 */
class TransferEngineError extends DataTransferError
{
    public function __construct(string $severity, ?string $message = null, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct('engine', $severity, $message, $details, $previous);
    }
}
