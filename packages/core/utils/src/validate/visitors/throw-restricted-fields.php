<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate\Visitors;

use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Utils;

/** Throws for the given attribute paths (exact or nested); `null` throws for everything. */
final class ThrowRestrictedFields
{
    /** @param list<string>|null $restrictedFields */
    public function __construct(private readonly ?array $restrictedFields = null)
    {
    }

    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        $path = $options->path->attribute;

        if ($this->restrictedFields === null) {
            Utils::throwInvalidKey(['key' => $options->key, 'path' => $path]);
        }

        foreach ($this->restrictedFields as $field) {
            if (!is_string($field)) {
                throw new \TypeError('Expected array of strings for restrictedFields but got "' . get_debug_type($this->restrictedFields) . '"');
            }
        }

        if ($path !== null && in_array($path, $this->restrictedFields, true)) {
            Utils::throwInvalidKey(['key' => $options->key, 'path' => $path]);
        }

        foreach ($this->restrictedFields as $allowedPath) {
            if ($path !== null && str_starts_with($path, "{$allowedPath}.")) {
                Utils::throwInvalidKey(['key' => $options->key, 'path' => $path]);
            }
        }
    }
}
