<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Blocks;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;

/** nexus' `UnionDefinitionBlock`: `t.members(...types)`. */
final class UnionDefinitionBlock
{
    /** @var list<string|NamedTypeDef> */
    private array $members = [];

    public function members(string|NamedTypeDef ...$members): void
    {
        foreach ($members as $member) {
            $this->members[] = $member;
        }
    }

    /** @return list<string|NamedTypeDef> */
    public function getMembers(): array
    {
        return $this->members;
    }
}
