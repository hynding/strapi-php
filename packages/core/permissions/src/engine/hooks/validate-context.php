<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Hooks;

/** `{ get permission() }` handed to the validate hooks: a read-only copy of the permission. */
final class ValidateContext
{
    /** @param array<string, mixed> $permission */
    public function __construct(private readonly array $permission)
    {
    }

    /** @return array<string, mixed> */
    public function permission(): array
    {
        return $this->permission;
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
