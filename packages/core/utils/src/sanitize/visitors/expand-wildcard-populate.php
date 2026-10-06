<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Expands `populate: '*'` into `{ relation: true, component: true, ... }` for the current schema. */
final class ExpandWildcardPopulate
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if ($options->key === '' && $options->value === '*') {
            $newPopulateQuery = [];
            foreach (ContentTypes::attributes($options->schema) as $name => $attribute) {
                if (in_array($attribute['type'] ?? null, ['relation', 'component', 'media', 'dynamiczone'], true)) {
                    $newPopulateQuery[$name] = true;
                }
            }

            $utils->set('', $newPopulateQuery);
        }
    }
}
