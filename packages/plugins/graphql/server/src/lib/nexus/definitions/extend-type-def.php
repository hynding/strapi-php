<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Definitions;

/** nexus `extendType({ type, definition(t) })` (and `extendInputType()` with `$input`). */
final class ExtendTypeDef
{
    public readonly string $type;

    /** @var callable */
    public readonly mixed $definition;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, public readonly bool $input = false)
    {
        $type = $config['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new \InvalidArgumentException('extendType requires a "type"');
        }
        $definition = $config['definition'] ?? null;
        if (!is_callable($definition)) {
            throw new \InvalidArgumentException("extendType({$type}) requires a \"definition\" function");
        }
        $this->type = $type;
        $this->definition = $definition;
    }
}
