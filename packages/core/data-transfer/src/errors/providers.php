<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Errors;

/**
 * Port of src/errors/providers.ts: `ProviderError`. The step-specific subclasses
 * (`ProviderInitializationError`, `ProviderValidationError`, `ProviderTransferError`) live in
 * errors/providers/ (one class per file).
 *
 * `details` is `['step' => 'initialization'|'validation'|'transfer', 'details' => mixed, 'code' => ?string]`.
 */
class ProviderError extends DataTransferError
{
    public function __construct(string $severity, ?string $message = null, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct('provider', $severity, $message, $details, $previous);
    }
}
