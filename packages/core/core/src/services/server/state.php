<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Types\Core\State as StateContract;

/** `ctx.state`: a mutable bag (route, user, auth, isAuthenticated...). */
final class State implements StateContract
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function route(): ?array
    {
        $route = $this->data['route'] ?? null;

        return is_array($route) ? $route : null;
    }

    public function user(): ?array
    {
        $user = $this->data['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    public function auth(): ?array
    {
        $auth = $this->data['auth'] ?? null;
        if (!is_array($auth) || !is_array($auth['strategy'] ?? null) || !array_key_exists('credentials', $auth)) {
            return null;
        }

        return $auth;
    }

    public function isAuthenticated(): bool
    {
        return ($this->data['isAuthenticated'] ?? false) === true;
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
