<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Definitions;

/** nexus `arg({ type, default, description })` (and `stringArg()`, `idArg()`...). */
final class ArgDef
{
    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config)
    {
        if (!array_key_exists('type', $config)) {
            throw new \InvalidArgumentException('arg() requires a "type"');
        }
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self([...$this->config, ...$changes]);
    }
}
