<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Error;

use Strapi\Utils\Errors\ApplicationError;

/** Port of `UnexpectedError` (packages/utils/upgrade/src/modules/error/utils.ts). */
class UnexpectedError extends ApplicationError
{
    public string $name = 'UnexpectedError';

    public function __construct()
    {
        parent::__construct('Unexpected Error');
    }
}
