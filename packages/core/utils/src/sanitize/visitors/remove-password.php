<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Removes password attributes. */
final class RemovePassword
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if (($options->attribute['type'] ?? null) === 'password') {
            $utils->remove($options->key);
        }
    }
}
