<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/**
 * Koa's `ctx.cookies` (the `cookies` npm module): read request cookies, queue `Set-Cookie`
 * headers on the response.
 *
 * Options of `set()`: `maxAge` (ms), `expires` (\DateTimeInterface), `path` (default `/`),
 * `domain`, `secure`, `httpOnly` (default true), `sameSite` (`true`|`'strict'`|`'lax'`|`'none'`|false),
 * `signed`, `overwrite`, `priority`, `partitioned`.
 */
interface Cookies
{
    /** @param array{signed?: bool}|null $opts */
    public function get(string $name, ?array $opts = null): ?string;

    /** @param array<string, mixed>|null $opts */
    public function set(string $name, ?string $value, ?array $opts = null): static;
}
