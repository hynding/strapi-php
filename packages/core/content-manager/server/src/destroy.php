<?php

declare(strict_types=1);

namespace Strapi\ContentManager;

use Strapi\Core\Strapi;

/**
 * Port of server/src/destroy.ts. `history.destroy` (Enterprise licence) is not ported.
 */
final class Destroy
{
    public function __invoke(Strapi $strapi): void
    {
    }
}
