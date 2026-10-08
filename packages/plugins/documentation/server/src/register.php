<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation;

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Middlewares\Documentation;

/** Port of server/src/register.ts. */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        Documentation::addDocumentMiddlewares($strapi);
    }
}
