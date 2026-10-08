<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission;

use Strapi\Admin\Domain\Permission\Permission as PermissionDomain;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Engine as PermissionsEngine;
use Strapi\Permissions\Engine\Hooks\BeforeEvaluateContext;
use Strapi\Permissions\Engine\Hooks\ValidateContext;

/**
 * Port of server/src/services/permission/engine.ts (`createPermissionEngine({ providers })`).
 */
final class Engine
{
    private readonly PermissionsEngine $engineInstance;

    /**
     * @param array{providers: array{action: \Strapi\Admin\Domain\Action\Provider, condition: \Strapi\Admin\Domain\Condition\Provider}} $params
     */
    public function __construct(private readonly Strapi $strapi, array $params)
    {
        $providers = $params['providers'];

        $this->engineInstance = PermissionsEngine::new(['providers' => $providers])
            /*
             * Validate the permission's action exists in the action registry
             */
            ->on('before-format::validate.permission', function (BeforeEvaluateContext $ctx) use ($providers): ?bool {
                $permission = $ctx->permission();
                $action = $providers['action']->get((string) ($permission['action'] ?? ''));

                // If the action isn't registered into the action provider, then ignore the permission
                if ($action === null) {
                    $this->strapi->log()->debug(
                        sprintf('Unknown action "%s" supplied when registering a new permission in engine', (string) ($permission['action'] ?? '')),
                    );

                    return false;
                }

                return null;
            })
            /*
             * Remove invalid properties from the permission based on the action (applyToProperties)
             */
            ->on('format.permission', static function (array $permission) use ($providers): array {
                $action = $providers['action']->get((string) ($permission['action'] ?? '')) ?? [];
                $properties = is_array($permission['properties'] ?? null) ? $permission['properties'] : [];

                // Only keep the properties allowed by the action (action.applyToProperties)
                // Upstream reads `action.applyToProperties` (not `action.options.applyToProperties`), which is
                // never set: every property is kept. The behavior is preserved.
                $propertiesName = array_map('strval', array_keys($properties));
                $allowed = is_array($action['applyToProperties'] ?? null) ? $action['applyToProperties'] : $propertiesName;
                $invalidProperties = array_values(array_diff($propertiesName, $allowed));

                foreach ($invalidProperties as $property) {
                    $permission = PermissionDomain::deleteProperty($property, $permission);
                }

                return $permission;
            })
            /*
             * Ignore the permission if the fields property is an empty array (access to no field)
             */
            ->on('after-format::validate.permission', static function (ValidateContext $ctx): ?bool {
                $permission = $ctx->permission();
                $fields = $permission['properties']['fields'] ?? null;

                if (is_array($fields) && $fields === []) {
                    return false;
                }

                return null;
            });
    }

    /** @return array<string, \Strapi\Utils\Hooks\Hook> */
    public function hooks(): array
    {
        return $this->engineInstance->hooks();
    }

    /** Register a handler on the underlying engine's hook (`engine.hooks[name].register`). */
    public function on(string $hook, callable $handler): self
    {
        $this->engineInstance->on($hook, $handler);

        return $this;
    }

    public function engineInstance(): PermissionsEngine
    {
        return $this->engineInstance;
    }

    /**
     * Generate an ability based on the given user (using associated roles & permissions).
     *
     * @param array<string, mixed> $user
     */
    public function generateUserAbility(array $user): Ability
    {
        /** @var \Strapi\Admin\Services\Permission $permissionService */
        $permissionService = $this->strapi->service('admin::permission');
        /** @var list<array{action: string}> $permissions admin permissions always carry an action */
        $permissions = $permissionService->findUserPermissions($user);

        return $this->engineInstance->generateAbility($permissions, $user);
    }

    /**
     * Generate an ability based on an admin token's stored permissions, scoped to the owner.
     * Token permissions are already validated and ceiling-clamped at write time.
     *
     * @param list<array<string, mixed>> $tokenPermissions
     * @param array<string, mixed> $owner
     */
    public function generateTokenAbility(array $tokenPermissions, array $owner): Ability
    {
        /** @var list<array{action: string}> $tokenPermissions */
        return $this->engineInstance->generateAbility($tokenPermissions, $owner);
    }

    /**
     * Check many permissions based on an ability.
     *
     * @param list<array<string, mixed>> $permissions
     * @return list<bool>
     */
    public function checkMany(Ability $ability, array $permissions): array
    {
        return array_map(
            static fn (array $p): bool => $ability->can((string) ($p['action'] ?? ''), $p['subject'] ?? null, isset($p['field']) ? (string) $p['field'] : null),
            array_values($permissions),
        );
    }
}
