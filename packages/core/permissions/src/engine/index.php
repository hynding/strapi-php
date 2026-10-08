<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine;

use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\CaslAbility;
use Strapi\Permissions\Engine\Abilities\CustomAbilityBuilder;
use Strapi\Utils\Hooks\AsyncParallelHook;
use Strapi\Utils\ProviderFactory;
use Strapi\Utils\Qs;

/**
 * Port of packages/core/permissions/src/engine/index.ts (`engine.new(params)`).
 *
 * ```php
 * $engine = Engine::new(['providers' => ['action' => $actionProvider, 'condition' => $conditionProvider]]);
 * $engine->on('before-evaluate.permission', fn ($ctx) => ...);
 * $ability = $engine->generateAbility($permissions, $options);
 * ```
 *
 * Providers are anything exposing `get(string $id)` (a {@see ProviderFactory}); a condition is an
 * array or object with a callable `handler` receiving `[...options, 'permission' => $permission]` and
 * returning `true`, `false` or a Mongo-style query array.
 *
 * @phpstan-type Permission array{action: string, actionParameters?: array<string, mixed>, subject?: string|null, properties?: array<string, mixed>, conditions?: list<string>}
 * @phpstan-type Condition array{name?: string, handler: callable}|object
 * @phpstan-type Providers array{action: object, condition: object}
 * @phpstan-import-type PermissionRule from CaslAbility
 */
final class Engine
{
    /** @var array<string, \Strapi\Utils\Hooks\Hook> */
    private array $hooks;

    /** @var \Closure(): CustomAbilityBuilder */
    private \Closure $abilityBuilderFactory;

    /** @var \Closure(callable, array<string, mixed>): \Closure|null  test seam mirroring vi.spyOn(engine, 'createRegisterFunction') */
    private ?\Closure $createRegisterFunctionOverride = null;

    /**
     * @param Providers $providers
     * @param callable(): CustomAbilityBuilder|null $abilityBuilderFactory
     */
    public function __construct(private readonly array $providers, ?callable $abilityBuilderFactory = null)
    {
        $this->hooks = Hooks::createEngineHooks();
        $this->abilityBuilderFactory = $abilityBuilderFactory !== null ? $abilityBuilderFactory(...) : CaslAbility::caslAbilityBuilder(...);
    }

    /**
     * Upstream `engine.new(params)`.
     *
     * @param array{providers: Providers, abilityBuilderFactory?: callable(): CustomAbilityBuilder} $params
     */
    public static function new(array $params): self
    {
        return new self($params['providers'], $params['abilityBuilderFactory'] ?? null);
    }

    /** @return array<string, \Strapi\Utils\Hooks\Hook> */
    public function hooks(): array
    {
        return $this->hooks;
    }

    /** Register a new handler for a given hook. */
    public function on(string $hook, callable $handler): self
    {
        $validHooks = array_keys($this->hooks);

        if (!in_array($hook, $validHooks, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid hook supplied when trying to register an handler to the permission engine. Got "%s" but expected one of %s',
                $hook,
                implode(', ', $validHooks),
            ));
        }

        $this->hooks[$hook]->register($handler);

        return $this;
    }

    /**
     * Create a register function that wraps a `can` function used to register a permission in the
     * ability builder. The rule is passed by reference so the before-register hook can add conditions.
     *
     * @param callable(PermissionRule): mixed $can
     * @param array<string, mixed> $options
     * @return \Closure(PermissionRule): mixed
     */
    public function createRegisterFunction(callable $can, array $options): \Closure
    {
        if ($this->createRegisterFunctionOverride !== null) {
            return ($this->createRegisterFunctionOverride)($can, $options);
        }

        return $this->defaultRegisterFunction($can, $options);
    }

    /**
     * @param callable(PermissionRule): mixed $can
     * @param array<string, mixed> $options
     * @return \Closure(PermissionRule): mixed
     */
    public function defaultRegisterFunction(callable $can, array $options): \Closure
    {
        return function (array $permission) use ($can, $options): mixed {
            $hookContext = Hooks::createWillRegisterContext(['options' => $options, 'permission' => &$permission]);

            $this->hooks['before-register.permission']->call($hookContext);

            // the hook received the rule by reference and may have rewritten it
            self::assertPermissionRule($permission);

            return $can($permission);
        };
    }

    /**
     * @param array<array-key, mixed> $rule
     * @phpstan-assert PermissionRule $rule
     */
    private static function assertPermissionRule(array $rule): void
    {
        $action = $rule['action'] ?? null;
        $validAction = is_string($action)
            || (is_array($action) && is_string($action['name'] ?? null) && is_array($action['params'] ?? null));
        $validSubject = ($rule['subject'] ?? null) === null || is_string($rule['subject']);
        $validProperties = ($rule['properties'] ?? null) === null || is_array($rule['properties']);
        $validCondition = ($rule['condition'] ?? null) === null || is_array($rule['condition']);

        if (!$validAction || !$validSubject || !$validProperties || !$validCondition) {
            throw new \InvalidArgumentException('Invalid permission rule: expected { action, subject?, properties?, condition? }');
        }
    }

    /**
     * Replace `createRegisterFunction` (what upstream tests do with `vi.spyOn`), e.g. to record calls.
     *
     * @param callable(callable, array<string, mixed>): \Closure|null $factory
     */
    public function setCreateRegisterFunction(?callable $factory): void
    {
        $this->createRegisterFunctionOverride = $factory === null ? null : $factory(...);
    }

    /**
     * Generate an ability based on the instance's ability builder and the given permissions.
     *
     * @param list<Permission> $permissions
     * @param array<string, mixed> $options
     */
    public function generateAbility(array $permissions, array $options = []): Ability
    {
        $builder = ($this->abilityBuilderFactory)();
        $can = $builder->can(...);

        foreach ($permissions as $permission) {
            $register = $this->createRegisterFunction($can, $options);

            $this->evaluate($permission, $options, $register);
        }

        return $builder->build();
    }

    /**
     * Evaluate a permission using local and registered behaviors (using hooks).
     * Validate, format (add condition, etc...), evaluate (evaluate conditions) and register a permission.
     *
     * @param Permission $permission
     * @param array<string, mixed> $options
     * @param callable(PermissionRule): mixed $register
     */
    private function evaluate(array $permission, array $options, callable $register): mixed
    {
        $preFormatValidation = $this->hooks['before-format::validate.permission']->call(Hooks::createBeforeEvaluateContext($permission));

        if ($preFormatValidation === false) {
            return null;
        }

        $formatted = $this->hooks['format.permission']->call($permission);
        $permission = is_array($formatted) ? $formatted : $permission;

        $afterFormatValidation = $this->hooks['after-format::validate.permission']->call(Hooks::createValidateContext($permission));

        if ($afterFormatValidation === false) {
            return null;
        }

        $this->hooks['before-evaluate.permission']->call(Hooks::createBeforeEvaluateContext($permission));

        $actionName = $permission['action'];
        $subject = $permission['subject'] ?? null;
        $properties = $permission['properties'] ?? null;
        $conditions = $permission['conditions'] ?? [];
        $actionParameters = $permission['actionParameters'] ?? [];

        $action = $actionName;

        if (is_array($actionParameters) && $actionParameters !== []) {
            $action = "{$actionName}?" . Qs::stringify($actionParameters);
        }

        $rule = ['action' => $action, 'subject' => $subject, 'properties' => $properties];

        if ($conditions === []) {
            return $register($rule);
        }

        $resolved = [];
        foreach ($conditions as $id) {
            $provider = $this->providers['condition'];
            $condition = method_exists($provider, 'get') ? $provider->get($id) : null;
            $handler = self::handlerOf($condition);
            // remove invalid conditions (unregistered, or without a callable handler)
            if ($handler !== null) {
                $resolved[] = $handler;
            }
        }

        $evaluated = [];
        foreach ($resolved as $handler) {
            $result = $handler([...$options, 'permission' => AsyncParallelHook::cloneDeep($permission)]);
            // remove invalid results
            if (is_bool($result) || is_array($result) || is_object($result)) {
                $evaluated[] = $result;
            }
        }

        // `[].every(...)` is true in JavaScript: a permission whose conditions were all invalid is denied
        if (array_reduce($evaluated, static fn (bool $carry, mixed $r): bool => $carry && $r === false, true)) {
            return null;
        }

        if ($evaluated === [] || in_array(true, $evaluated, true)) {
            return $register($rule);
        }

        $results = array_values(array_filter($evaluated, static fn (mixed $r): bool => is_array($r) || is_object($r)));

        if ($results === []) {
            return $register($rule);
        }

        return $register([...$rule, 'condition' => ['$and' => [['$or' => $results]]]]);
    }

    private static function handlerOf(mixed $condition): ?\Closure
    {
        if (is_array($condition)) {
            $handler = $condition['handler'] ?? null;
        } elseif (is_object($condition)) {
            if (method_exists($condition, 'handler')) {
                $handler = [$condition, 'handler'];
            } else {
                $handler = $condition->handler ?? null;
            }
        } else {
            return null;
        }

        return is_callable($handler) ? $handler(...) : null;
    }
}
