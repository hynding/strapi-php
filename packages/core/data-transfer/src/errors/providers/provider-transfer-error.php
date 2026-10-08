<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Errors\Providers;

use Strapi\DataTransfer\Errors\Constants;
use Strapi\DataTransfer\Errors\ProviderError;

/** Port of `ProviderTransferError` (src/errors/providers.ts). */
class ProviderTransferError extends ProviderError
{
    public function __construct(?string $message = null, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct(Constants::FATAL, $message, ['step' => 'transfer', 'details' => $details], $previous);
    }
}
