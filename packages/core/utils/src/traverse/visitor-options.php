<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;

/**
 * Everything a visitor sees about the node being visited. Shared by entity and query traversals.
 */
final class VisitorOptions
{
    /** @var \Closure(string): (Schema|array<string, mixed>|null) */
    public readonly \Closure $getModel;

    /**
     * @param mixed $data the node (object/array/string) holding $key
     * @param Schema|array<string, mixed>|null $schema
     * @param array<string, mixed>|null $attribute
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     * @param list<string>|null $allowedExtraRootKeys
     */
    public function __construct(
        public readonly mixed $data,
        public readonly Schema|array|null $schema,
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?array $attribute,
        public readonly Path $path,
        callable $getModel,
        public readonly ?ParentNode $parent = null,
        public readonly ?array $allowedExtraRootKeys = null,
    ) {
        $this->getModel = $getModel(...);
    }

    /** @return Schema|array<string, mixed>|null */
    public function getModel(string $uid): Schema|array|null
    {
        return ($this->getModel)($uid);
    }
}
