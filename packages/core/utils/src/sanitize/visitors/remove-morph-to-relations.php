<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

final class RemoveMorphToRelations
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if (ContentTypes::isMorphToRelationalAttribute($options->attribute)) {
            $utils->remove($options->key);
        }
    }
}
