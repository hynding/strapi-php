<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\EnumTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/services/builders/enums.ts */
final class Enums
{
    /**
     * Build a Nexus enum type from a Strapi enum attribute
     *
     * @param array<string, mixed> $definition - The definition of the enum (`enum`: the values)
     * @param string $name - The name of the enum
     */
    public function buildEnumTypeDefinition(array $definition, string $name): EnumTypeDef
    {
        $members = [];
        foreach (is_array($definition['enum'] ?? null) ? $definition['enum'] : [] as $value) {
            $members[Strings::toRegressedEnumValue((string) $value)] = $value;
        }

        return Nexus::enumType([
            'name' => $name,
            'members' => $members,
        ]);
    }
}
