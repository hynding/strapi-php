<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Removes attributes flagged `private: true` or listed in the model/global private attributes. */
final class RemovePrivate
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if ($options->attribute === null) {
            return;
        }

        $isPrivate = ($options->attribute['private'] ?? null) === true || ContentTypes::isPrivateAttribute($options->schema, $options->key);

        if ($isPrivate) {
            $utils->remove($options->key);
        }
    }
}
