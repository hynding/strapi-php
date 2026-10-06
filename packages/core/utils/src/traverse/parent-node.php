<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;

/** Upstream `Parent`; "Parent" is a reserved word in PHP. The node that led to the current traversal level (`{ key, path, schema, attribute }`). */
final class ParentNode
{
    /**
     * @param Schema|array<string, mixed>|null $schema
     * @param array<string, mixed>|null $attribute
     */
    public function __construct(
        public readonly ?string $key,
        public readonly Path $path,
        public readonly Schema|array|null $schema,
        public readonly ?array $attribute = null,
    ) {
    }
}
