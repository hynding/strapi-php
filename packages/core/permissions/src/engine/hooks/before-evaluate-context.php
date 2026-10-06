<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Hooks;

use Strapi\Permissions\Domain\Permission\Permission;

/** `{ get permission(), addCondition(condition) }` handed to the before-evaluate hook. */
final class BeforeEvaluateContext
{
    /** @var array<string, mixed> */
    private array $permission;

    /** @param array<string, mixed> $permission */
    public function __construct(array &$permission)
    {
        $this->permission = &$permission;
    }

    /** @return array<string, mixed> a copy of the current permission */
    public function permission(): array
    {
        return $this->permission;
    }

    public function addCondition(string $condition): self
    {
        /** @var array<string, mixed> $updated */
        $updated = Permission::addCondition($condition, $this->permission);
        foreach ($updated as $key => $value) {
            $this->permission[$key] = $value;
        }

        return $this;
    }

    public function __get(string $name): mixed
    {
        return $name === 'permission' ? $this->permission : null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'permission';
    }
}
