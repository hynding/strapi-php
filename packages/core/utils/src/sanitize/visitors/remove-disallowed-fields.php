<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Keeps only the given attribute paths (and their ancestors/descendants); `null` allows everything.
 * Upstream exports a factory: `removeDisallowedFields(allowedFields)` → visitor.
 */
final class RemoveDisallowedFields
{
    /** @param list<string>|null $allowedFields */
    public function __construct(private readonly ?array $allowedFields = null)
    {
    }

    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        // All fields are allowed
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

        $containedPaths = self::getContainedPaths($path);

        foreach ($this->allowedFields as $p) {
            if (in_array($p, $containedPaths, true) || str_starts_with($p, "{$path}.")) {
                return;
            }
        }

        $utils->remove($options->key);
    }

    /**
     * `'foo.bar.field'` → `['foo', 'foo.bar', 'foo.bar.field']`.
     *
     * @return list<string>
     */
    public static function getContainedPaths(string $path): array
    {
        $parts = Objects::toPath($path);
        $out = [];
        foreach ($parts as $index => $value) {
            $out[] = implode('.', array_slice($parts, 0, $index + 1));
        }

        return $out;
    }
}
