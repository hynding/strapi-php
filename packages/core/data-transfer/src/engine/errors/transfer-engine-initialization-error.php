<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine\Errors;

use Strapi\DataTransfer\Engine\TransferEngineError;
use Strapi\DataTransfer\Errors\Constants;

/** Port of `TransferEngineInitializationError` (src/engine/errors.ts). */
class TransferEngineInitializationError extends TransferEngineError
{
    public function __construct(?string $message = null)
    {
        parent::__construct(Constants::FATAL, $message, ['step' => 'initialization']);
    }
}
