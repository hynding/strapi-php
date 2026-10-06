<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\Config;

/** strapi.config — dot-path access over merged config/*.php. */
interface Config
{
    public function get(string $path, mixed $default = null): mixed;

    public function set(string $path, mixed $value): void;

    public function has(string $path): bool;

    /** @return array<string, mixed> */
    public function all(): array;
}
