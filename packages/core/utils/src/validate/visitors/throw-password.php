<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate\Visitors;

use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Utils;

final class ThrowPassword
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if (($options->attribute['type'] ?? null) === 'password') {
            Utils::throwInvalidKey(['key' => $options->key, 'path' => $options->path->attribute]);
        }
    }
}
