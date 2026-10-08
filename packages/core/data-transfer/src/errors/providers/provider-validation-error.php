<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Errors\Providers;

use Strapi\DataTransfer\Errors\Constants;
use Strapi\DataTransfer\Errors\ProviderError;

/** Port of `ProviderValidationError` (src/errors/providers.ts). */
class ProviderValidationError extends ProviderError
{
    public function __construct(?string $message = null, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct(Constants::SILLY, $message, ['step' => 'validation', 'details' => $details], $previous);
    }
}
