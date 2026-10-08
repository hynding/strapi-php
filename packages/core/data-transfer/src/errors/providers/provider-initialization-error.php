<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Errors\Providers;

use Strapi\DataTransfer\Errors\Constants;
use Strapi\DataTransfer\Errors\ProviderError;

/** Port of `ProviderInitializationError` (src/errors/providers.ts). */
class ProviderInitializationError extends ProviderError
{
    public function __construct(?string $message = null, ?\Throwable $previous = null)
    {
        parent::__construct(Constants::FATAL, $message, ['step' => 'initialization'], $previous);
    }
}
