<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate\Visitors;

use Strapi\Utils\Sanitize\Visitors\RemoveDisallowedFields;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Utils;

/** Throws for attribute paths outside the allowed list; `null` allows everything. */
final class ThrowDisallowedFields
{
    /** @param list<string>|null $allowedFields */
    public function __construct(private readonly ?array $allowedFields = null)
    {
    }

    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if ($this->allowedFields === null) {
            return;
        }

        foreach ($this->allowedFields as $field) {
            if (!is_string($field)) {
                throw new \TypeError('Expected array of strings for allowedFields but got "' . get_debug_type($this->allowedFields) . '"');
            }
        }

        $path = $options->path->attribute;
        if ($path === null) {
            return;
        }

        $containedPaths = RemoveDisallowedFields::getContainedPaths($path);

        foreach ($this->allowedFields as $p) {
            if (in_array($p, $containedPaths, true) || str_starts_with($p, "{$path}.")) {
                return;
            }
        }

        Utils::throwInvalidKey(['key' => $options->key, 'path' => $path]);
    }
}
