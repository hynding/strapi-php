<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\Domain\Action\Provider as ActionProvider;
use Strapi\Admin\Domain\Condition\Provider as ConditionProvider;
use Strapi\Admin\Domain\Permission\Permission as PermissionDomain;
use Strapi\Admin\Services\Permission\Engine;
use Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager;
use Strapi\Admin\Services\Permission\Queries;
use Strapi\Admin\Services\Permission\SectionsBuilder\Builder;
use Strapi\Admin\Services\Permission\SectionsBuilder\SectionsBuilder;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/permission.ts: queries, utils, the engine and the action/condition
 * providers. The providers, sections builder and engine are created once per service instance
 * (upstream: once per module load).
 */
final class Permission
{
    public readonly ActionProvider $actionProvider;

    public readonly ConditionProvider $conditionProvider;

    public readonly Builder $sectionsBuilder;

    public readonly Engine $engine;

    private readonly Queries $queries;

    public function __construct(private readonly Strapi $strapi)
    {
        $isLoaded = static fn (): bool => $strapi->isLoaded();

        $this->actionProvider = ActionProvider::createActionProvider([], $isLoaded);
        $this->conditionProvider = ConditionProvider::createConditionProvider($isLoaded);
        $this->sectionsBuilder = SectionsBuilder::createDefaultSectionBuilder();
        $this->engine = new Engine($strapi, [
            'providers' => ['action' => $this->actionProvider, 'condition' => $this->conditionProvider],
        ]);
        $this->queries = new Queries($strapi);
    }

    // Queries / Actions

    public function cleanPermissionsInDatabase(): void
    {
        $this->queries->cleanPermissionsInDatabase();
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    public function createMany(array $permissions): array
    {
        return $this->queries->createMany($permissions);
    }

    /** @param list<int|string> $ids */
    public function deleteByIds(array $ids): void
    {
        $this->queries->deleteByIds($ids);
    }

    /** @param list<int|string> $rolesIds */
    public function deleteByRolesIds(array $rolesIds): void
    {
        $this->queries->deleteByRolesIds($rolesIds);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function findMany(array $params = []): array
    {
        return $this->queries->findMany($params);
    }

    /**
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function findUserPermissions(array $user): array
    {
        return $this->queries->findUserPermissions($user);
    }

    // Utils

    /**
     * @param array{ability: \Strapi\Permissions\Engine\Abilities\Ability, action?: string|null, model?: string|null} $params
     */
    public function createPermissionsManager(array $params): PermissionsManager
    {
        return PermissionsManager::createPermissionsManager($this->strapi, $params);
    }

    /**
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public function sanitizePermission(array $permission): array
    {
        return PermissionDomain::sanitizePermissionFields($permission);
    }
}
