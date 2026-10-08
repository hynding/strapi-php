<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Definitions;

use GraphQL\Type\Definition\ScalarType;

/**
 * nexus `scalarType({ name, serialize, parseValue, parseLiteral, description, asNexusMethod })`, or
 * `asNexusMethod(scalar, methodName)` (then `$scalar` is the ready-made graphql scalar).
 */
final class ScalarTypeDef extends NamedTypeDef
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config, public readonly ?ScalarType $scalar = null)
    {
        parent::__construct($config);
    }

    /** The `t.<method>()` shorthand this scalar adds to definition blocks (`asNexusMethod`). */
    public function nexusMethod(): ?string
    {
        $method = $this->config['asNexusMethod'] ?? null;

        return is_string($method) && $method !== '' ? $method : null;
    }
}
