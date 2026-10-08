<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine\Errors;

use Strapi\DataTransfer\Engine\TransferEngineError;
use Strapi\DataTransfer\Errors\Constants;

/** Port of `TransferEngineValidationError` (src/engine/errors.ts). */
class TransferEngineValidationError extends TransferEngineError
{
    /** @param array<string, mixed>|null $details */
    public function __construct(?string $message = null, ?array $details = null)
    {
        parent::__construct(Constants::FATAL, $message, ['step' => 'validation', 'details' => $details]);
    }
}
