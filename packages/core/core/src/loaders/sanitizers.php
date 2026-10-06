<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/loaders/sanitizers.ts. */
final class Sanitizers
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->get('sanitizers')->set('content-api', ['input' => [], 'output' => [], 'query' => []]);
    }
}
