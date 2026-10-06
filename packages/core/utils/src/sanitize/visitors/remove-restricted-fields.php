<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Removes the given attribute paths (exact or nested); `null` removes everything.
 * Upstream exports a factory: `removeRestrictedFields(restrictedFields)` → visitor.
 */
final class RemoveRestrictedFields
{
    /** @param list<string>|null $restrictedFields */
    public function __construct(private readonly ?array $restrictedFields = null)
    {
    }

    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        // Remove all fields
        if ($this->restrictedFields === null) {
            $utils->remove($options->key);

            return;
        }

        foreach ($this->restrictedFields as $field) {
            if (!is_string($field)) {
                throw new \TypeError('Expected array of strings for restrictedFields but got "' . get_debug_type($this->restrictedFields) . '"');
            }
        }

        $path = $options->path->attribute;

        // Remove if an exact match was found
        if ($path !== null && in_array($path, $this->restrictedFields, true)) {
            $utils->remove($options->key);

            return;
        }

        // Remove nested matches
        foreach ($this->restrictedFields as $allowedPath) {
            if ($path !== null && str_starts_with($path, "{$allowedPath}.")) {
                $utils->remove($options->key);

                return;
            }
        }
    }
}
