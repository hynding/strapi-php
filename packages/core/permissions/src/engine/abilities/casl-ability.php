<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/**
 * Port of packages/core/permissions/src/engine/abilities/casl-ability.ts (`caslAbilityBuilder`).
 *
 * `CaslAbility::caslAbilityBuilder()` returns a {@see CustomAbilityBuilder} whose `can(permissionRule)`
 * registers a Strapi {@see \Strapi\Permissions\PermissionRule}-shaped array
 * (`['action' => ..., 'subject' => ..., 'properties' => ['fields' => [...]], 'condition' => [...]]`)
 * and whose `build()` returns an {@see Ability} matching conditions with {@see Sift}.
 *
 * @phpstan-type ParametrizedAction array{name: string, params: array<string, mixed>}
 * @phpstan-type PermissionRule array{action: string|ParametrizedAction, subject?: string|null, properties?: array{fields?: list<string>|null}, condition?: array<string, mixed>|null}
 */
final class CaslAbility
{
    public const ALLOWED_OPERATIONS = Sift::ALLOWED_OPERATIONS;

    /**
     * Match an RBAC condition query against an entity in memory with sift.
     *
     * @param array<string, mixed> $conditions
     * @return \Closure(mixed): bool
     */
    public static function conditionsMatcher(array $conditions): \Closure
    {
        return Rule::conditionsMatcher($conditions, self::ALLOWED_OPERATIONS);
    }

    /** @param ParametrizedAction $parametrizedAction */
    public static function buildParametrizedAction(array $parametrizedAction): string
    {
        return Ability::buildParametrizedAction($parametrizedAction);
    }

    public static function caslAbilityBuilder(): CustomAbilityBuilder
    {
        return new CustomAbilityBuilder();
    }
}
