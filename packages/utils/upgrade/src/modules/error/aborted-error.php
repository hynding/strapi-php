<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Error;

use Strapi\Utils\Errors\ApplicationError;

/** Port of `AbortedError` (packages/utils/upgrade/src/modules/error/utils.ts). */
class AbortedError extends ApplicationError
{
    public string $name = 'AbortedError';

    public function __construct(string $message = 'Upgrade aborted')
    {
        parent::__construct($message);
    }
}
