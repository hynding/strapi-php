<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;

/** Handler context inside a Factory traversal (the visitor options plus the visitor itself). */
final class Context
{
    /** @var \Closure(VisitorOptions, VisitorUtils): void */
    public readonly \Closure $visitor;

    /** @var \Closure(string): (Schema|array<string, mixed>|null) */
    public readonly \Closure $getModel;

    /**
     * @param array<string, mixed>|null $attribute
     * @param Schema|array<string, mixed>|null $schema
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?array $attribute,
        public readonly Schema|array|null $schema,
        public readonly Path $path,
        public readonly mixed $data,
        callable $visitor,
        callable $getModel,
        public readonly ?ParentNode $parent = null,
    ) {
        $this->visitor = $visitor(...);
        $this->getModel = $getModel(...);
    }

    /** @return Schema|array<string, mixed>|null */
    public function getModel(string $uid): Schema|array|null
    {
        return ($this->getModel)($uid);
    }

    /** The parent node to hand to a nested traversal of this key. */
    public function asParent(): ParentNode
    {
        return new ParentNode($this->key, $this->path, $this->schema, $this->attribute);
    }
}
