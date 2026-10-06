<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/loaders/validators.ts. */
final class Validators
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->get('validators')->set('content-api', ['input' => [], 'query' => []]);
    }
}
