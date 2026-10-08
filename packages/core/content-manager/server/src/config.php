<?php

declare(strict_types=1);

namespace Strapi\ContentManager;

/**
 * Port of server/src/config.ts. Upstream's plugin entry (index.ts) does not export it, so the
 * module array does not either; it is kept for parity.
 */
final class Config
{
    /** @var array<string, mixed> */
    public const array DEFAULT = [];

    public static function validator(): void
    {
    }
}
