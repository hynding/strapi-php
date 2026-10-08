<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql;

use Strapi\Types\Core\Context;
use Strapi\Types\Core\State;

/**
 * The GraphQL context value resolvers receive (PHP-port addition): bootstrap.ts builds
 * `{ state: ctx.state, koaContext: ctx }` per request, Apollo clones it per operation and the
 * root-query-args plugin adds `rootQueryArgsByPath`. Readable as properties or array offsets
 * (`$context->state`, `$context['koaContext']`).
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class GraphqlContext implements \ArrayAccess
{
    /**
     * Root query arguments keyed by the root field's response key (alias), plus `_originField`.
     *
     * @var array<string|int, array<string, mixed>>|null
     */
    public ?array $rootQueryArgsByPath = null;

    /** @var array<string, mixed> anything else resolvers or plugins attach */
    private array $extra = [];

    public function __construct(public readonly State $state, public readonly Context $koaContext)
    {
    }

    /**
     * `context.state.auth` of any context value (`GraphqlContext`, array or null).
     *
     * @return array<string, mixed>|null
     */
    public static function authOf(mixed $context): ?array
    {
        $state = self::stateOf($context);
        $auth = $state?->auth();

        return $auth === [] ? null : $auth;
    }

    public static function stateOf(mixed $context): ?State
    {
        if ($context instanceof self) {
            return $context->state;
        }
        if (is_array($context) && ($context['state'] ?? null) instanceof State) {
            return $context['state'];
        }

        return null;
    }

    public static function koaContextOf(mixed $context): ?Context
    {
        if ($context instanceof self) {
            return $context->koaContext;
        }
        if (is_array($context) && ($context['koaContext'] ?? null) instanceof Context) {
            return $context['koaContext'];
        }

        return null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['state', 'koaContext'], true) || ($offset === 'rootQueryArgsByPath' && $this->rootQueryArgsByPath !== null) || array_key_exists((string) $offset, $this->extra);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'state' => $this->state,
            'koaContext' => $this->koaContext,
            'rootQueryArgsByPath' => $this->rootQueryArgsByPath,
            default => $this->extra[(string) $offset] ?? null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === 'rootQueryArgsByPath') {
            $this->rootQueryArgsByPath = is_array($value) ? $value : null;

            return;
        }
        if (in_array($offset, ['state', 'koaContext'], true)) {
            throw new \LogicException("The GraphQL context's {$offset} cannot be replaced");
        }
        $this->extra[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        if ($offset === 'rootQueryArgsByPath') {
            $this->rootQueryArgsByPath = null;

            return;
        }
        unset($this->extra[(string) $offset]);
    }

    public function __get(string $name): mixed
    {
        return $this->extra[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->extra[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->extra[$name]);
    }
}
