<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

/** The `{ raw, attribute, rawWithIndices }` path tracked by every traversal. */
final class Path
{
    public function __construct(
        public readonly ?string $raw = null,
        public readonly ?string $attribute = null,
        public readonly ?string $rawWithIndices = null,
    ) {
    }

    public function withRaw(?string $raw): self
    {
        return new self($raw, $this->attribute, $this->rawWithIndices);
    }

    /** @return array{raw: string|null, attribute: string|null, rawWithIndices: string|null} */
    public function toArray(): array
    {
        return ['raw' => $this->raw, 'attribute' => $this->attribute, 'rawWithIndices' => $this->rawWithIndices];
    }
}
