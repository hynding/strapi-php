<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;

/**
 * Port of server/src/services/permission-checker.ts.
 *
 * The service is `{ create }`; `create(['userAbility' => $ability, 'model' => $uid])` returns a
 * checker (an instance of this same class bound to an ability and a model).
 *
 * Upstream's shortcuts `can.read(entity, field)`, `cannot.update(entity)`,
 * `requiresEntity.read()` and `sanitizedQuery.read(query)` are the same methods called with the
 * {@see ACTIONS} key instead of an action uid: `can('read', $entity, $field)`,
 * `cannot('update', $entity)`, `requiresEntity('read')`, `sanitizedQuery($query, 'read')`.
 * (No action uid is a bare key: every uid contains `::`.)
 */
final class PermissionChecker
{
    public const array ACTIONS = [
        'read' => 'plugin::content-manager.explorer.read',
        'create' => 'plugin::content-manager.explorer.create',
        'update' => 'plugin::content-manager.explorer.update',
        'delete' => 'plugin::content-manager.explorer.delete',
        'publish' => 'plugin::content-manager.explorer.publish',
        'unpublish' => 'plugin::content-manager.explorer.publish',
        'discard' => 'plugin::content-manager.explorer.update',
    ];

    private ?PermissionsManager $permissionsManager = null;

    public function __construct(
        private readonly Strapi $strapi,
        public readonly ?Ability $userAbility = null,
        public readonly ?string $model = null,
    ) {
        if ($userAbility !== null) {
            $this->permissionsManager = $this->adminPermission()->createPermissionsManager([
                'ability' => $userAbility,
                'model' => $model,
            ]);
        }
    }

    /**
     * Typed as `object` natively so that unit tests can register stubs.
     *
     * @return \Strapi\Admin\Services\Permission
     */
    private function adminPermission(): object
    {
        /** @var \Strapi\Admin\Services\Permission $service */
        $service = $this->strapi->service('admin::permission');

        return $service;
    }

    /** @param array{userAbility: Ability, model: string} $params */
    public function create(array $params): self
    {
        return new self($this->strapi, $params['userAbility'], $params['model']);
    }

    private function manager(): PermissionsManager
    {
        if ($this->permissionsManager === null) {
            throw new \LogicException('permission-checker: call create({ userAbility, model }) first');
        }

        return $this->permissionsManager;
    }

    private function ability(): Ability
    {
        if ($this->userAbility === null) {
            throw new \LogicException('permission-checker: call create({ userAbility, model }) first');
        }

        return $this->userAbility;
    }

    private static function resolveAction(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    /** @return list<string> */
    private function aliases(string $action): array
    {
        return $this->adminPermission()->actionProvider->unstable_aliases($action, $this->model);
    }

    private function toSubject(mixed $entity = null): mixed
    {
        return is_array($entity) || is_object($entity) ? $this->manager()->toSubject($entity, $this->model) : $this->model;
    }

    /** check if you have the permission */
    public function can(string $action, mixed $entity = null, ?string $field = null): bool
    {
        $action = self::resolveAction($action);
        $subject = $this->toSubject($entity);
        $ability = $this->ability();

        // Test the original action to see if it passes
        if ($ability->can($action, $subject, $field)) {
            return true;
        }

        // Else try every known alias if at least one of them succeed, then the user "can"
        foreach ($this->aliases($action) as $alias) {
            if ($ability->can($alias, $subject, $field)) {
                return true;
            }
        }

        return false;
    }

    /** check if you don't have the permission */
    public function cannot(string $action, mixed $entity = null, ?string $field = null): bool
    {
        $action = self::resolveAction($action);
        $subject = $this->toSubject($entity);
        $ability = $this->ability();

        // Test both the original action
        if (!$ability->cannot($action, $subject, $field)) {
            return false;
        }

        // and every known alias, if all of them fail (cannot), then the user truly "cannot"
        foreach ($this->aliases($action) as $alias) {
            if (!$ability->cannot($alias, $subject, $field)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $options */
    public function sanitizeOutput(mixed $data, array $options = []): mixed
    {
        $action = $options['action'] ?? self::ACTIONS['read'];

        return $this->manager()->sanitizeOutput($data, ['subject' => $this->toSubject($data), 'action' => $action]);
    }

    /** @return list<\Strapi\Permissions\Engine\Abilities\Rule> */
    private function getRulesForAction(string $action): array
    {
        $actions = [$action, ...$this->aliases($action)];

        $rules = [];
        foreach ($actions as $actionName) {
            foreach ($this->ability()->rulesFor($actionName, $this->model ?? 'all') as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /** Tell callers if we need the full entity to check access */
    public function requiresEntity(string $action): bool
    {
        foreach ($this->getRulesForAction(self::resolveAction($action)) as $rule) {
            $conditions = $rule->conditions;
            if ($conditions !== null && $conditions !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $options */
    public function sanitizeQuery(mixed $query, array $options = []): mixed
    {
        $action = $options['action'] ?? self::ACTIONS['read'];

        return $this->manager()->sanitizeQuery($query, ['subject' => $this->model, 'action' => $action]);
    }

    private function sanitizeInput(string $action, mixed $data, mixed $entity = null): mixed
    {
        return $this->manager()->sanitizeInput($data, [
            'subject' => $entity !== null ? $this->toSubject($entity) : $this->model,
            'action' => $action,
        ]);
    }

    /** @param array<string, mixed> $options */
    public function validateQuery(mixed $query, array $options = []): mixed
    {
        $action = $options['action'] ?? self::ACTIONS['read'];

        return $this->manager()->validateQuery($query, ['subject' => $this->model, 'action' => $action]);
    }

    public function validateInput(string $action, mixed $data, mixed $entity = null): mixed
    {
        return $this->manager()->validateInput($data, [
            'subject' => $entity !== null ? $this->toSubject($entity) : $this->model,
            'action' => $action,
        ]);
    }

    public function sanitizeCreateInput(mixed $data): mixed
    {
        return $this->sanitizeInput(self::ACTIONS['create'], $data);
    }

    /** @return \Closure(mixed): mixed */
    public function sanitizeUpdateInput(mixed $entity): \Closure
    {
        return fn (mixed $data): mixed => $this->sanitizeInput(self::ACTIONS['update'], $data, $entity);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function buildPermissionQuery(array $query, ?string $action = null): array
    {
        return $this->manager()->addPermissionsQueryTo($query, $action);
    }

    /**
     * Sanitized queries (`sanitizedQuery(query, { action })`; the shortcuts `sanitizedQuery.read(query)`
     * are `sanitizedQuery($query, 'read')`).
     *
     * Upstream's shortcuts pass the action *string* where `sanitizeQuery` destructures
     * `{ action = ACTIONS.read }`, so the query is always sanitized for `read` while the
     * permission query is built for the requested action; this keeps that behaviour.
     *
     * @param array<string, mixed> $query
     * @param string|array{action?: string} $action an ACTIONS key or action uid (shortcut form), or `['action' => uid]`
     * @return array<string, mixed>
     */
    public function sanitizedQuery(array $query, string|array $action = []): array
    {
        if (is_string($action)) {
            $actionUid = self::resolveAction($action);
            $sanitized = $this->sanitizeQuery($query);
        } else {
            $actionUid = $action['action'] ?? null;
            $sanitized = $this->sanitizeQuery($query, $actionUid !== null ? ['action' => $actionUid] : []);
        }

        return $this->buildPermissionQuery(is_array($sanitized) ? $sanitized : [], $actionUid);
    }
}
