<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/** ctx.state — a mutable bag with the well-known keys Strapi uses. */
interface State
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    /** @return array<string, mixed> */
    public function all(): array;

    /** @return array<string, mixed>|null ctx.state.route — the matched route definition */
    public function route(): ?array;

    /** @return array<string, mixed>|null ctx.state.user */
    public function user(): ?array;

    /** @return array{strategy: array<string, mixed>, credentials: mixed, ability?: mixed}|null ctx.state.auth */
    public function auth(): ?array;

    public function isAuthenticated(): bool;
}
