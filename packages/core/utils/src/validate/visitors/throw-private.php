<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate\Visitors;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Utils;

final class ThrowPrivate
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if ($options->attribute === null) {
            return;
        }

        $isPrivate = ($options->attribute['private'] ?? null) === true || ContentTypes::isPrivateAttribute($options->schema, $options->key);

        if ($isPrivate) {
            Utils::throwInvalidKey(['key' => $options->key, 'path' => $options->path->attribute]);
        }
    }
}
