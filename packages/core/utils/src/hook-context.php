<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Mutable `{ key, value }` context passed to series hooks, so handlers can change the value in place
 * (JavaScript passes objects by reference; PHP arrays are copied, hence this small holder).
 */
final class HookContext
{
    public function __construct(public readonly string $key, public mixed $value)
    {
    }
}
