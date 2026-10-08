<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Definitions;

/**
 * Base of nexus' named type definitions (`objectType()`, `inputObjectType()`, `enumType()`...):
 * a name plus the config object the factory received, built into a graphql type by `makeSchema`.
 */
abstract class NamedTypeDef
{
    public readonly string $name;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config)
    {
        $name = $config['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException(static::class . ' requires a "name"');
        }
        $this->name = $name;
    }

    public function description(): ?string
    {
        $description = $this->config['description'] ?? null;

        return is_string($description) ? $description : null;
    }
}
